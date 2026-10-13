<?php

declare(strict_types=1);

use Firefly\Observability\Orchestration\TracerOrchestrationTracing;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Testing\Double\RecordingTracer;

it('wraps an execution and its steps in INTERNAL spans', function (): void {
    $tracer = new RecordingTracer;
    $tracing = new TracerOrchestrationTracing($tracer);

    $result = $tracing->traceExecution('checkout', ExecutionPattern::Saga, 'c-1', fn (): string => $tracing->traceStep('checkout', 'charge', 'c-1', fn (): string => 'charged'));

    expect($result)->toBe('charged');

    $execution = $tracer->find('saga checkout');
    $step = $tracer->find('checkout.charge');
    expect($execution?->kind)->toBe(SpanKind::Internal)
        ->and($execution?->attributes)->toBe(['firefly.orchestration.pattern' => 'SAGA', 'firefly.orchestration.name' => 'checkout', 'firefly.orchestration.correlation_id' => 'c-1'])
        ->and($step?->attributes)->toBe(['firefly.orchestration.name' => 'checkout', 'firefly.orchestration.step' => 'charge', 'firefly.orchestration.correlation_id' => 'c-1'])
        ->and($execution?->ended)->toBeTrue();
});

it('records a failing step as ERROR and rethrows', function (): void {
    $tracer = new RecordingTracer;

    expect(fn () => (new TracerOrchestrationTracing($tracer))->traceStep('checkout', 'charge', 'c-2', function (): never {
        throw new RuntimeException('card declined');
    }))->toThrow(RuntimeException::class, 'card declined');

    expect($tracer->recorded()[0]->status)->toBe(SpanStatus::Error);
});
