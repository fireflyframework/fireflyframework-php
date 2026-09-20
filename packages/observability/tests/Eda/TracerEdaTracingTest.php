<?php

declare(strict_types=1);

use Firefly\Eda\EventEnvelope;
use Firefly\Observability\Eda\TracerEdaTracing;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;
use Firefly\Testing\Double\RecordingTracer;

it('starts a PRODUCER span on publish and hands the transport the headers plus traceparent', function () {
    $tracer = new RecordingTracer;
    $tracing = new TracerEdaTracing($tracer, new W3CTraceContextPropagator);
    $sent = null;

    $tracing->tracePublish('orders', 'order.created', ['x-correlation-id' => 'c1'], function (array $headers) use (&$sent): void {
        $sent = $headers;
    });

    $span = $tracer->find('publish orders');
    expect($span?->kind)->toBe(SpanKind::Producer)
        ->and($span?->ended)->toBeTrue()
        ->and($span?->attributes)->toBe([
            'messaging.system' => 'firefly-eda',
            'messaging.operation.type' => 'publish',
            'messaging.destination.name' => 'orders',
            'firefly.eda.event_type' => 'order.created',
        ])
        ->and($sent)->toBe([
            'x-correlation-id' => 'c1',
            'traceparent' => '00-'.$span?->traceId().'-'.$span?->spanId().'-01',
        ]);
});

it('starts a CONSUMER span on consume, continued from the envelope traceparent, and records a failing subscriber', function () {
    $tracer = new RecordingTracer;
    $tracing = new TracerEdaTracing($tracer, new W3CTraceContextPropagator);
    $envelope = new EventEnvelope('order.paid', 'orders', ['id' => 1], ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    $tracing->traceConsume($envelope, static function (): void {});
    expect(fn () => $tracing->traceConsume($envelope, static function (): never {
        throw new RuntimeException('handler failed');
    }))->toThrow(RuntimeException::class);

    [$ok, $failed] = $tracer->recorded();
    expect($ok->name)->toBe('process orders')
        ->and($ok->kind)->toBe(SpanKind::Consumer)
        ->and($ok->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($ok->parent?->spanId)->toBe('00f067aa0ba902b7')
        ->and($ok->attributes['messaging.operation.type'])->toBe('process')
        ->and($ok->attributes['messaging.message.id'])->toBe($envelope->eventId)
        ->and($failed->status)->toBe(SpanStatus::Error)
        ->and($failed->exception?->getMessage())->toBe('handler failed')
        ->and($failed->ended)->toBeTrue();
});

it('starts a new root on consume when the envelope carries no traceparent', function () {
    $tracer = new RecordingTracer;
    (new TracerEdaTracing($tracer, new W3CTraceContextPropagator))->traceConsume(new EventEnvelope('x', 'd'), static function (): void {});

    expect($tracer->recorded()[0]->parent)->toBeNull()->and($tracer->recorded()[0]->context()->isValid())->toBeTrue();
});
