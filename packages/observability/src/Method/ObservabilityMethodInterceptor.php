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
 * Spring makes the same call: Boot's observation advisors run outside both `@EnableMethodSecurity`'s
 * interceptors and `@EnableTransactionManagement`'s, and `management.observations` documents the span as
 * covering "the method invocation including its cross-cutting concerns".
 *
 * `inertWhenUnbound` is TRUE on the advice (see ObservabilityAdviceSource): the interceptor bean exists only
 * while `firefly.observability.method.enabled` and the metrics master switch are on, and an application that
 * turns metrics off has asked for exactly that — a PassThroughInterceptor, not a boot failure. The key is
 * ALSO read live on every call, because a test flips it after boot and the proxy already holds this
 * instance (the reason MethodSecurityInterceptor reads its two flags live).
 *
 * TAGS ARE BOUNDED BY CONSTRUCTION. `class` is the declared class's SHORT name and `method` is the method
 * name — both from the compiled descriptor, both finite in the size of the codebase. `exception` is a
 * thrown class's short name. Nothing this class writes is derived from an argument value, so no meter it
 * creates can grow with traffic.
 */
final class ObservabilityMethodInterceptor implements MethodInterceptor
{
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
            $span?->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());

            throw $e;
        } finally {
            $elapsed = microtime(true) - $started;
            $exception = $thrown === null ? 'none' : $this->shortName($thrown::class);

            $this->recordTimed($rule, $base, $elapsed, $exception);
            $this->recordCounted($rule, $base, $exception, $thrown !== null);
            $this->recordObserved($rule, $base, $elapsed, $exception);

            if ($inFlight !== null) {
                $this->recorder->setGauge($inFlight, $base, 0.0);
            }

            $span?->end();
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

        return $this->tracer->startSpan($name, SpanKind::Internal, $attributes);
    }

    /**
     * The in-flight half of a LongTaskTimer: a set-gauge raised to 1 for the duration of THIS invocation and
     * returned to 0 in the caller's `finally`. Returns the gauge name so the finally block can clear it
     * without recomputing it — and null when the rule is not a long task, which is the common case and must
     * cost nothing.
     *
     * @param  array<string, string>  $base
     */
    private function enterLongTask(ObservabilityMethodDescriptor $rule, array $base): ?string
    {
        if ($rule->timed === null || ! $rule->timed['longTask']) {
            return null;
        }

        $name = $this->timedName($rule).'.active';
        $this->recorder->setGauge($name, $base, 1.0);

        return $name;
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
