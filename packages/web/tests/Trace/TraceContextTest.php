<?php

declare(strict_types=1);

use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Http\Request;

it('reads the trace id off the request attributes', function (): void {
    $request = Request::create('/orders');
    $request->attributes->set(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');

    expect(TraceContext::traceId($request))->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('rejects an id that is not a valid W3C trace id', function (): void {
    foreach (['', 'not-hex', '00000000000000000000000000000000', '4BF92F3577B34DA6A3CE929D0E0E4736', 'abc'] as $bad) {
        $request = Request::create('/orders');
        $request->attributes->set(TraceContext::TRACE_ID, $bad);

        expect(TraceContext::traceId($request))->toBeNull();
    }
});

it('answers null when nothing published a trace id', function (): void {
    expect(TraceContext::traceId(Request::create('/orders')))->toBeNull();
});

it('publishes the trace id as the reference when there is one', function (): void {
    $request = Request::create('/orders');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-123');
    $request->attributes->set(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');

    expect(TraceContext::referenceFor($request))->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('falls back to the correlation id when there is no trace', function (): void {
    $request = Request::create('/orders');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-123');

    expect(TraceContext::referenceFor($request))->toBe('corr-123');
});
