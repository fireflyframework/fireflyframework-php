<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\SpanKind;
use Firefly\Observability\Tracing\SpanStatus;
use Firefly\Testing\Double\RecordedSpan;
use Firefly\Testing\Double\RecordingTracer;

/**
 * startSpan() is typed by the port (Span), so every assertion on a RecordedSpan field goes through
 * recorded()/find(), which are typed by the double — that keeps PHPStan honest about what the port promises.
 */
it('records a started span with real-looking ids and makes it current until it ends', function () {
    $tracer = new RecordingTracer;

    $started = $tracer->startSpan('GET', SpanKind::Server, ['http.request.method' => 'GET']);
    $span = $tracer->recorded()[0];

    expect($span)->toBeInstanceOf(RecordedSpan::class)
        ->and($span->context()->isValid())->toBeTrue()
        ->and($span->isRecording())->toBeTrue()
        ->and($tracer->currentSpan())->toBe($started)
        ->and($tracer->spans)->toBe(['GET'])
        ->and($span->attributes)->toBe(['http.request.method' => 'GET'])
        ->and($span->ended)->toBeFalse();

    $started->end();

    expect($tracer->currentSpan())->toBeNull()
        ->and($span->ended)->toBeTrue();
});

it('nests: a child started under a current span shares its trace id and records it as parent', function () {
    $tracer = new RecordingTracer;
    $parentSpan = $tracer->startSpan('parent');
    $childSpan = $tracer->startSpan('child');
    [$parent, $child] = $tracer->recorded();

    expect($child->traceId())->toBe($parent->traceId())
        ->and($child->spanId())->not->toBe($parent->spanId())
        ->and($child->parent?->spanId)->toBe($parent->spanId())
        ->and($tracer->currentSpan())->toBe($childSpan);

    $childSpan->end();
    expect($tracer->currentSpan())->toBe($parentSpan);
    $parentSpan->end();
});

it('continues an explicit remote parent, and starts a new root for an explicitly invalid one', function () {
    $tracer = new RecordingTracer;
    $remote = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true, 'congo=t61rcWkgMzE', remote: true);

    $tracer->startSpan('GET', SpanKind::Server, [], $remote);
    $tracer->startSpan('detached', SpanKind::Internal, [], SpanContext::invalid());
    [$continued, $root] = $tracer->recorded();

    expect($continued->traceId())->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($continued->parent?->spanId)->toBe('00f067aa0ba902b7')
        ->and($continued->context()->traceState)->toBe('congo=t61rcWkgMzE')
        ->and($root->traceId())->not->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($root->parent)->toBeNull();
});

it('captures name changes, attributes, events, status and exceptions', function () {
    $tracer = new RecordingTracer;
    $e = new RuntimeException('boom');

    $started = $tracer->startSpan('GET');
    $started->updateName('GET /orders/{id}')
        ->setAttribute('http.route', '/orders/{id}')
        ->setAttributes(['http.response.status_code' => 500])
        ->addEvent('retry', ['attempt' => 2])
        ->setStatus(SpanStatus::Error, 'boom')
        ->recordException($e);
    $started->end();

    $span = $tracer->find('GET /orders/{id}');

    expect($span)->not->toBeNull()
        ->and($span?->name)->toBe('GET /orders/{id}')
        ->and($span?->attributes)->toBe(['http.route' => '/orders/{id}', 'http.response.status_code' => 500])
        ->and($span?->events)->toBe([
            ['name' => 'retry', 'attributes' => ['attempt' => 2]],
            ['name' => 'exception', 'attributes' => ['exception.type' => RuntimeException::class, 'exception.message' => 'boom']],
        ])
        ->and($span?->status)->toBe(SpanStatus::Error)
        ->and($span?->statusDescription)->toBe('boom')
        ->and($span?->exception)->toBe($e)
        ->and($tracer->ofKind(SpanKind::Internal))->toBe([$span]);
});

it('trace() ends the span on the way out and records a throwable before rethrowing it', function () {
    $tracer = new RecordingTracer;

    expect(fn () => $tracer->trace('failing', function (): never {
        throw new RuntimeException('nope');
    }, SpanKind::Client))->toThrow(RuntimeException::class);

    $span = $tracer->find('failing');

    expect($span?->ended)->toBeTrue()
        ->and($span?->kind)->toBe(SpanKind::Client)
        ->and($span?->status)->toBe(SpanStatus::Error)
        ->and($span?->exception?->getMessage())->toBe('nope')
        ->and($tracer->currentSpan())->toBeNull();
});

it('reset() forgets everything, including the active stack', function () {
    $tracer = new RecordingTracer;
    $tracer->startSpan('a');
    $tracer->reset();

    expect($tracer->spans)->toBe([])->and($tracer->recorded())->toBe([])->and($tracer->currentSpan())->toBeNull();
});
