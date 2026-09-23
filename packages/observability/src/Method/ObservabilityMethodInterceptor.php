<?php

declare(strict_types=1);

namespace Firefly\Observability\Method;

use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use Throwable;

/**
 * The proxy link that records Micrometer's method attributes on ANY stereotyped bean — the ported
 * TimedAspect, CountedAspect and ObservedAspect, folded into one because they share a clock.
 *
 * WHY IT IS THE OUTERMOST ADVICE (order 50, ahead of security's 100 and the transaction's 1000). The three
 * numbers answer three different questions, and only one ordering answers all of them honestly:
 *
 *   - A timer must measure what the CALLER experienced. A #[PreAuthorize] refusal costs an expression
 *     evaluation and a role-hierarchy walk; a #[Transactional] method costs a BEGIN and a COMMIT. Both are
 *     time the caller waited for. At order 50 the timer's clock starts before either and stops after both,
 *     so `orders.place` is the latency a client would measure from outside — which is the only latency worth
 *     alerting on.
 *   - A refusal must still be COUNTED, and counted as a failure. At any order inside security, an
 *     AccessDeniedException would be thrown before this link ran and the meter would simply never see the
 *     call: an application under a permissions misconfiguration would show falling traffic and a flat error
 *     rate, which is the exact shape of a silent outage.
 *   - A commit failure must be attributed to the method, not to the transaction. At order 50 a
 *     deadlock thrown by COMMIT is recorded with `exception=QueryException` on `orders.place`; inside the
 *     transactional link it would be invisible, because the method body had already returned.
 *
 * THE ORDER IS A DELIBERATE DIVERGENCE FROM SPRING, not parity with it — the one place in this package
 * where the precedent is the thing being rejected, so nobody re-derives it from Micrometer and "corrects"
 * the number. Micrometer's TimedAspect, CountedAspect and ObservedAspect are plain `@Aspect` classes: not
 * one of them implements `Ordered` or carries `@Order`, and Spring AOP gives an unordered advisor
 * `Ordered.LOWEST_PRECEDENCE`, so all three run INNERMOST. That places them INSIDE Spring Security's method
 * interceptors — `AuthorizationInterceptorsOrder` runs PRE_FILTER 100, PRE_AUTHORIZE 200, SECURED 300,
 * JSR250 400, POST_AUTHORIZE 500, POST_FILTER 600, the highest precedence of the three concerns and
 * therefore the outermost — and level with the transaction advisor, whose
 * `@EnableTransactionManagement(order = …)` also defaults to `Ordered.LOWEST_PRECEDENCE`. The consequence
 * is the first two silences the bullets above describe, in Spring, today: a `@Timed` method whose
 * `@PreAuthorize` denies is neither timed nor counted. LaraFly reads that placement as the defect rather
 * than the authority — the three arguments above are the whole justification for 50, and no Spring constant
 * is being ported into it. The chain the number produces (metrics 50, then security 100, then the
 * transaction 1000) is asserted on the compiled plan by CachedBootTest's layered fixture, so the ordering
 * is a test rather than only a paragraph.
 *
 * `inertWhenUnbound` is TRUE on the advice (see ObservabilityAdviceSource): the interceptor bean exists only
 * while `firefly.observability.method.enabled` and the metrics master switch are on, and an application that
 * turns metrics off has asked for exactly that — a PassThroughInterceptor, not a boot failure. The key is
 * ALSO read live on every call, because a test flips it after boot and the proxy already holds this
 * instance (the reason MethodSecurityInterceptor reads its two flags live).
 *
 * TELEMETRY NEVER CHANGES THE CALL. Every recorder and tracer touch here goes through bestEffort(): the
 * three record*() calls in the `finally`, the in-flight gauge on the way in and on the way out, and the
 * span's start, its exception and its end. HttpExchangeFilter::record() carries the same guard and gives the
 * reason in full — with a cache-backed registry (`firefly.observability.metrics.store`) every one of those
 * touches is cache I/O, so one Redis blip would otherwise turn every successful #[Timed] method into a
 * throw. It would be worse than that in a `finally`: an exception raised there DISCARDS the exception
 * already in flight, leaving it only as $previous, so the OutOfStockException a caller wrote a `catch` for
 * would arrive as a metrics failure no `catch` in the application matches. This link sits outside EVERY
 * annotated method, so the blast radius is the whole application rather than one filter's. A lost sample is
 * the correct price for a telemetry failure; a changed return value or a swapped exception never is.
 *
 * THE LONG-TASK GAUGE COUNTS DEPTH, NOT A FLAG. `<meter>.active` holds the number of invocations THIS
 * PROCESS currently has in flight for that meter identity — kept in $longTaskDepth, published on entry and
 * on exit. A plain 1-on-entry/0-on-exit flag reads 0 while work is still running the moment a long task
 * recurses or is re-entered, and self-invocation DOES re-enter here: the generated proxy is a SUBCLASS, so a
 * `$this->importAll()` inside the body dispatches through this link again. The gauge carries the TIMER's
 * tags ($base plus the attribute's extraTags), so `orders.import.active` joins to `orders.import` in a query
 * instead of sitting on a narrower tag set than the meter it describes.
 *
 * ACROSS PROCESSES IT IS STILL LAST-WRITER-WINS, and that is a Known-latent rather than an oversight:
 * MetricsRecorder::setGauge is a plain put — CacheMeterRegistry documents it as "a gauge is a snapshot, so
 * last-writer-wins is the correct semantic" — so with a store configured the shared key carries the depth of
 * whichever worker wrote last, not the fleet's total. Summing it would need an atomic increment on a gauge,
 * which the recorder port does not have. #[Timed]'s own docblock says the same where an application author
 * will read it.
 *
 * TAGS ARE BOUNDED BY CONSTRUCTION. `class` is the declared class's SHORT name and `method` is the method
 * name — both from the compiled descriptor, both finite in the size of the codebase. `exception` is a
 * thrown class's short name. Nothing this class writes is derived from an argument value, so no meter it
 * creates can grow with traffic.
 */
final class ObservabilityMethodInterceptor implements MethodInterceptor
{
    /**
     * In-flight depth per long-task gauge IDENTITY (meter name + its sorted tags), which is the granularity
     * the gauge itself is published at. Bounded like every tag this class writes — one entry per annotated
     * long-task method that is currently running — and the entry is dropped the moment its depth returns to
     * zero, so a long-lived Octane worker does not accumulate a row per method it has ever called.
     *
     * @var array<string, int>
     */
    private array $longTaskDepth = [];

    public function __construct(
        private readonly MetricsRecorder $recorder,
        private readonly Tracer $tracer,
        private readonly Config $config,
    ) {}

    public function invoke(MethodInvocation $invocation): mixed
    {
        $rule = $invocation->descriptor(ObservabilityMethodDescriptor::class);
        if ($rule === null || ! $this->config->bool('firefly.observability.method.enabled', true)) {
            return $invocation->proceed();
        }

        $base = ['class' => $this->shortName($invocation->getDeclaredClass()), 'method' => $invocation->getMethod()];
        $span = $this->startSpan($rule, $base);
        $inFlight = $this->enterLongTask($rule, $base);

        $started = microtime(true);
        $thrown = null;

        try {
            return $invocation->proceed();
        } catch (Throwable $e) {
            $thrown = $e;
            $this->bestEffort(static function () use ($span, $e): void {
                $span?->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());
            });

            throw $e;
        } finally {
            $elapsed = microtime(true) - $started;
            $exception = $thrown === null ? 'none' : $this->shortName($thrown::class);

            // Depth first, and OUTSIDE the recorder guard below: the bookkeeping is in-memory and cannot
            // fail, so a gauge write that does fail loses one sample rather than leaking a level of depth
            // that would keep the meter reading in-flight work for the rest of the process's life.
            if ($inFlight !== null) {
                $this->leaveLongTask($inFlight);
            }

            // The three meters share one registry, so they share one guard — if it is down they are all
            // down. The span is a different port and gets its own: a tracer failure must not cost the
            // meters, and a registry failure must not leave a span open.
            $this->bestEffort(function () use ($rule, $base, $elapsed, $exception, $thrown): void {
                $this->recordTimed($rule, $base, $elapsed, $exception);
                $this->recordCounted($rule, $base, $exception, $thrown !== null);
                $this->recordObserved($rule, $base, $elapsed, $exception);
            });

            $this->bestEffort(static function () use ($span): void {
                $span?->end();
            });
        }
    }

    /**
     * Best-effort by construction: a recording failure must never change what the method returns or throws.
     *
     * The guard HttpExchangeFilter::record() carries, for the reason it documents and one more that only
     * applies here — most of these calls run in a `finally`, where a throw DISCARDS the exception already on
     * its way to the caller. See the class docblock.
     *
     * @param  callable(): void  $work
     */
    private function bestEffort(callable $work): void
    {
        try {
            $work();
        } catch (Throwable) {
            // Intentionally swallowed — see the docblock. The signal is lost; the call is not.
        }
    }

    /**
     * The span half of #[Observed], started before the call so it encloses everything the inner advice does —
     * and returned to the caller, which is the only frame that knows when the call really ended.
     *
     * @param  array<string, string>  $base
     */
    private function startSpan(ObservabilityMethodDescriptor $rule, array $base): ?Span
    {
        if ($rule->observed === null) {
            return null;
        }

        $name = $rule->observed['contextualName'] !== '' ? $rule->observed['contextualName'] : $this->observedName($rule);

        /** @var array<string, bool|int|float|string|array<mixed>|null> $attributes */
        $attributes = [...$base, ...$rule->observed['tags']];

        try {
            return $this->tracer->startSpan($name, SpanKind::Internal, $attributes);
        } catch (Throwable) {
            // A tracer that cannot start a span must not stop the method running; the rest of the
            // invocation reads `$span === null` exactly as it does with tracing switched off.
            return null;
        }
    }

    /**
     * The in-flight half of a LongTaskTimer: this invocation joins the depth behind `<meter>.active` and the
     * new depth is published. Returns the gauge identity so the caller's `finally` can leave it again
     * without recomputing the name or the tags — and null when the rule is not a long task, which is the
     * common case and must cost nothing.
     *
     * @param  array<string, string>  $base
     * @return array{name: string, tags: array<string, string>}|null
     */
    private function enterLongTask(ObservabilityMethodDescriptor $rule, array $base): ?array
    {
        if ($rule->timed === null || ! $rule->timed['longTask']) {
            return null;
        }

        $gauge = ['name' => $this->timedName($rule).'.active', 'tags' => [...$base, ...$rule->timed['tags']]];
        $this->publishDepth($gauge, 1);

        return $gauge;
    }

    /**
     * @param  array{name: string, tags: array<string, string>}  $gauge
     */
    private function leaveLongTask(array $gauge): void
    {
        $this->publishDepth($gauge, -1);
    }

    /**
     * Moves the depth for one gauge identity and publishes it. `max(0, …)` is belt-and-braces against a
     * leave that never saw its enter (only reachable if the enter's own bookkeeping were skipped): a gauge
     * that went negative would be read as a broken exporter rather than as the zero it means.
     *
     * @param  array{name: string, tags: array<string, string>}  $gauge
     */
    private function publishDepth(array $gauge, int $delta): void
    {
        $key = $this->gaugeKey($gauge);
        $depth = max(0, ($this->longTaskDepth[$key] ?? 0) + $delta);

        if ($depth === 0) {
            unset($this->longTaskDepth[$key]);
        } else {
            $this->longTaskDepth[$key] = $depth;
        }

        $this->bestEffort(function () use ($gauge, $depth): void {
            $this->recorder->setGauge($gauge['name'], $gauge['tags'], (float) $depth);
        });
    }

    /**
     * The identity a gauge is published under, as a string key: the meter name plus its tags in a stable
     * order, because a tag map is unordered and two invocations of the same method must land on the same
     * depth counter however the merge happened to arrange it.
     *
     * @param  array{name: string, tags: array<string, string>}  $gauge
     */
    private function gaugeKey(array $gauge): string
    {
        $tags = $gauge['tags'];
        ksort($tags);

        $parts = [];
        foreach ($tags as $tag => $value) {
            $parts[] = $tag.'='.$value;
        }

        return $gauge['name'].'{'.implode(',', $parts).'}';
    }

    /**
     * @param  array<string, string>  $base
     */
    private function recordTimed(ObservabilityMethodDescriptor $rule, array $base, float $elapsed, string $exception): void
    {
        if ($rule->timed !== null) {
            $this->recorder->record($this->timedName($rule), [...$base, ...$rule->timed['tags'], 'exception' => $exception], $elapsed);
        }
    }

    /**
     * @param  array<string, string>  $base
     */
    private function recordCounted(ObservabilityMethodDescriptor $rule, array $base, string $exception, bool $failed): void
    {
        if ($rule->counted === null || ($rule->counted['failuresOnly'] && ! $failed)) {
            return;
        }

        $name = $rule->counted['name'] !== '' ? $rule->counted['name'] : $this->config->string('firefly.observability.method.counted.name', 'method.counted');

        $this->recorder->increment($name, [...$base, ...$rule->counted['tags'], 'result' => $failed ? 'failure' : 'success', 'exception' => $exception]);
    }

    /**
     * An #[Observed] records the TIMER half here; its span half was started before the call and is ended by
     * the caller's finally. One name, two signals — which is the entire promise of the Observation API.
     *
     * @param  array<string, string>  $base
     */
    private function recordObserved(ObservabilityMethodDescriptor $rule, array $base, float $elapsed, string $exception): void
    {
        if ($rule->observed !== null) {
            $this->recorder->record($this->observedName($rule), [...$base, ...$rule->observed['tags'], 'exception' => $exception], $elapsed);
        }
    }

    private function timedName(ObservabilityMethodDescriptor $rule): string
    {
        $name = $rule->timed === null ? '' : $rule->timed['name'];

        return $name !== '' ? $name : $this->config->string('firefly.observability.method.timed.name', 'method.timed');
    }

    private function observedName(ObservabilityMethodDescriptor $rule): string
    {
        $name = $rule->observed === null ? '' : $rule->observed['name'];

        return $name !== '' ? $name : $this->config->string('firefly.observability.method.observed.name', 'method.observed');
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
