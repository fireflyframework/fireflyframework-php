<?php

declare(strict_types=1);

namespace Firefly\Observability\Tracing\OpenTelemetry;

use Fiber;
use Firefly\Observability\Tracing\Span;
use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\Tracer;
use OpenTelemetry\API\Trace\Span as OtelSpan;
use OpenTelemetry\API\Trace\SpanContext as OtelSpanContext;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TracerProviderInterface;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Throwable;
use WeakMap;

/**
 * The Tracer port over the OpenTelemetry API. Built over a TracerProviderInterface rather than the SDK's
 * TracerProvider so an application that configures the SDK itself (or the auto-instrumentation extension's
 * global provider) can hand its provider in; OpenTelemetryAutoConfiguration builds the default one from
 * `firefly.observability.tracing.*`.
 *
 * Parent handling is the port's contract: null continues the SDK's current context (nesting), an invalid
 * SpanContext starts a new root (`setParent(false)`), and a valid one — extracted from a carrier by
 * W3CTraceContextPropagator — is converted to a remote OTel SpanContext and wrapped as the parent.
 */
final class OpenTelemetryTracer implements Tracer
{
    public const string SCOPE = 'firefly/observability';

    private readonly TracerInterface $tracer;

    /**
     * @var WeakMap<object, true> the fibers whose OTel context FLOOR this tracer laid itself — and only those: a
     *                            fiber whose context a foreign scope initialised is not recorded, because that scope
     *                            can be detached and the fiber read again (keyed by the Fiber object; a Fiber has
     *                            no generic-free type PHPStan accepts as a WeakMap key)
     */
    private WeakMap $flooredFibers;

    public function __construct(private readonly TracerProviderInterface $provider)
    {
        $this->tracer = $provider->getTracer(self::SCOPE);
        $this->flooredFibers = new WeakMap;
    }

    public function provider(): TracerProviderInterface
    {
        return $this->provider;
    }

    public function startSpan(string $name, SpanKind $kind = SpanKind::Internal, array $attributes = [], ?SpanContext $parent = null): Span
    {
        $this->ensureFiberContext();

        $builder = $this->tracer->spanBuilder($name === '' ? 'unnamed' : $name)->setSpanKind(self::kind($kind));

        // On the builder rather than on the started span, so a sampler that looks at attributes sees them.
        foreach ($attributes as $key => $value) {
            if ($key !== '') {
                $builder->setAttribute($key, $value);
            }
        }

        if ($parent !== null) {
            $builder->setParent($parent->isValid() ? $this->remoteParent($parent) : false);
        }

        return (new OpenTelemetrySpan($builder->startSpan(), null))->activated();
    }

    public function currentSpan(): ?Span
    {
        $this->ensureFiberContext();

        $current = OtelSpan::getCurrent();

        return $current->getContext()->isValid() ? new OpenTelemetrySpan($current, null) : null;
    }

    /**
     * @template T
     *
     * @param  callable(Span): T  $callback
     * @param  array<string, bool|int|float|string|array<mixed>|null>  $attributes
     * @return T
     */
    public function trace(string $name, callable $callback, SpanKind $kind = SpanKind::Internal, array $attributes = []): mixed
    {
        $span = $this->startSpan($name, $kind, $attributes);

        try {
            return $callback($span);
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(SpanStatus::Error, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Initialise the OTel context of the fiber this call runs in, before the read that follows.
     *
     * The API's context storage is FIBER-BOUND: every fiber has its own scope stack, and a read from a fiber
     * whose stack is EMPTY raises E_USER_WARNING ("must attach initial fiber context manually") — which
     * Laravel's error handler turns into an ErrorException, i.e. a 500 on the first traced request a
     * fiber-based server hands the framework (the browser test plugin's in-process AMP server, an Amp or
     * ReactPHP application server). Every span this tracer starts, and every currentSpan() read, is such a
     * read. The API's own remedy is the FFI fiber observer (OTEL_PHP_FIBERS_ENABLED) or attaching the
     * initial context by hand; this is the by-hand attach, done where the framework knows it is about to read.
     *
     * The root context is attached, never the main fiber's current context: a request handled in a fiber
     * must not nest under whatever span the main fiber happens to hold. It is attached ONLY when the fiber's
     * stack is empty — an application's own scope, or the FFI observer, may have initialised it first, and
     * pushing a root over that scope would silently make the framework's span a root instead of that scope's
     * child. The node the tracer attaches is never detached: it is the fiber's FLOOR, dropped with the fiber,
     * and that is why a floored fiber is memoised (the WeakMap forgets the fiber with the fiber) and never
     * probed again. A fiber found holding a foreign scope is NOT memoised: the storage warns on an empty
     * stack, not on a never-initialised one, so once that scope is detached — the end of the call an
     * instrumentation hook wrapped, a worker or keep-alive fiber between two units of work — the next read
     * would be the warning again; re-probing lays the floor at that moment instead. The probe is a
     * set_error_handler pair around one scope() read, cheap enough to repeat for as long as a fiber is
     * someone else's to initialise.
     */
    private function ensureFiberContext(): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null || isset($this->flooredFibers[$fiber])) {
            return;
        }

        if (self::fiberHasContext()) {
            return;
        }

        Context::storage()->attach(Context::getRoot());
        $this->flooredFibers[$fiber] = true;
    }

    /**
     * Whether the current fiber holds a scope RIGHT NOW — not whether it ever did: a stack emptied by a detach
     * reads the same as one never attached to. The storage offers no query for it — the empty-stack read IS
     * the signal, raised as E_USER_WARNING — so the probe reads under a handler that records the warning and
     * swallows it, instead of leaving it to Laravel's handler to throw.
     */
    private static function fiberHasContext(): bool
    {
        $initialised = true;
        set_error_handler(static function () use (&$initialised): bool {
            $initialised = false;

            return true;
        }, E_USER_WARNING);

        try {
            Context::storage()->scope();
        } finally {
            restore_error_handler();
        }

        return $initialised;
    }

    private function remoteParent(SpanContext $parent): ContextInterface
    {
        $remote = OtelSpanContext::createFromRemoteParent(
            $parent->traceId,
            $parent->spanId,
            $parent->sampled ? TraceFlags::SAMPLED : TraceFlags::DEFAULT,
            $parent->traceState === '' ? null : new TraceState($parent->traceState),
        );

        return Context::getCurrent()->withContextValue(OtelSpan::wrap($remote));
    }

    /** @return OtelSpanKind::KIND_* */
    private static function kind(SpanKind $kind): int
    {
        return match ($kind) {
            SpanKind::Internal => OtelSpanKind::KIND_INTERNAL,
            SpanKind::Server => OtelSpanKind::KIND_SERVER,
            SpanKind::Client => OtelSpanKind::KIND_CLIENT,
            SpanKind::Producer => OtelSpanKind::KIND_PRODUCER,
            SpanKind::Consumer => OtelSpanKind::KIND_CONSUMER,
        };
    }
}
