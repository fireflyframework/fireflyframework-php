<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\SpanContext;
use Firefly\Observability\Tracing\W3CTraceContextPropagator;

it('extracts a remote parent from traceparent and tracestate, case-insensitively and from list-valued headers', function () {
    $propagator = new W3CTraceContextPropagator;

    // The shape Illuminate\Http\Request::headers->all() hands back: lowercase names, list values.
    $context = $propagator->extract([
        'traceparent' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
        'TraceState' => ['congo=t61rcWkgMzE'],
        'accept' => ['application/json'],
    ]);

    expect($context)->not->toBeNull()
        ->and($context?->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($context?->spanId)->toBe('00f067aa0ba902b7')
        ->and($context?->sampled)->toBeTrue()
        ->and($context?->traceState)->toBe('congo=t61rcWkgMzE')
        ->and($context?->remote)->toBeTrue();
});

it('reads an unsampled flag and accepts a plain string carrier (an EDA envelope header map)', function () {
    $context = (new W3CTraceContextPropagator)->extract(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00']);

    expect($context?->sampled)->toBeFalse()
        ->and($context?->traceState)->toBe('');
});

it('returns null for a missing, malformed, version-ff or all-zero traceparent', function (mixed $traceparent) {
    expect((new W3CTraceContextPropagator)->extract(['traceparent' => $traceparent]))->toBeNull();
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => ['00-4bf92f3577b34da6-00f067aa0ba902b7-01'],
    'uppercase' => ['00-4BF92F3577B34DA6A3CE929D0E0E4736-00f067aa0ba902b7-01'],
    'version ff' => ['ff-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'],
    'zero trace id' => ['00-00000000000000000000000000000000-00f067aa0ba902b7-01'],
    'zero span id' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-0000000000000000-01'],
    'version 00 with trailing fields' => ['00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-extra'],
]);

it('tolerates a future version with trailing fields, as the spec asks', function () {
    $context = (new W3CTraceContextPropagator)->extract(['traceparent' => '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-future']);

    expect($context?->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('injects traceparent (and tracestate only when there is one) and round-trips through extract', function () {
    $propagator = new W3CTraceContextPropagator;
    $context = new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', true, 'congo=t61rcWkgMzE');

    $headers = $propagator->inject($context);

    expect($headers)->toBe([
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        'tracestate' => 'congo=t61rcWkgMzE',
    ])
        ->and($propagator->inject(new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '00f067aa0ba902b7', false)))
        ->toBe(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00'])
        ->and($propagator->extract($headers)?->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('injects nothing for an invalid context, so a NoOp tracer never writes a bogus header', function () {
    expect((new W3CTraceContextPropagator)->inject(SpanContext::invalid()))->toBe([]);
});
