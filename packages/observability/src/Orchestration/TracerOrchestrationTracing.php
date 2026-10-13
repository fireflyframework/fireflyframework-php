<?php

declare(strict_types=1);

namespace Firefly\Observability\Orchestration;

use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\Tracer;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Event\OrchestrationTracing;

/**
 * The real OrchestrationTracing — an INTERNAL span per execution (`saga checkout`) and a child per step
 * (`checkout.charge`), so a trace shows which step took the time and which one failed. Tracer::trace()
 * records a throwable as ERROR plus an exception event and rethrows, so the engine still sees the failure.
 */
final class TracerOrchestrationTracing implements OrchestrationTracing
{
    public function __construct(private readonly Tracer $tracer) {}

    /**
     * @template T
     *
     * @param  callable(): T  $execution
     * @return T
     */
    public function traceExecution(string $name, ExecutionPattern $pattern, string $correlationId, callable $execution): mixed
    {
        return $this->tracer->trace(strtolower($pattern->value).' '.$name, $execution, SpanKind::Internal, [
            'firefly.orchestration.pattern' => $pattern->value,
            'firefly.orchestration.name' => $name,
            'firefly.orchestration.correlation_id' => $correlationId,
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $step
     * @return T
     */
    public function traceStep(string $name, string $stepId, string $correlationId, callable $step): mixed
    {
        return $this->tracer->trace("{$name}.{$stepId}", $step, SpanKind::Internal, [
            'firefly.orchestration.name' => $name,
            'firefly.orchestration.step' => $stepId,
            'firefly.orchestration.correlation_id' => $correlationId,
        ]);
    }
}
