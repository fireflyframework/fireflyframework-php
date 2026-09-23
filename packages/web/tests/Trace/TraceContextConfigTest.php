<?php

declare(strict_types=1);

use Firefly\Web\Exception\ProblemDetailsRenderer;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;

/*
 * THE TWO KEYS, EXERCISED. TraceContextTest beside this file drives the defaults, which is the only branch a
 * bare Pest test can reach: TraceContext reads its configuration through the facade application, and a test
 * that never booted one is told "no config" and answers with the hardcoded defaults. So every assertion
 * there held with the `enabled()` guard deleted and the header key replaced by the literal 'X-Trace-Id' —
 * the kill switch and the '' -> no-echo rule were documented claims nothing checked.
 *
 * Orchestra\Testbench\TestCase boots a real (minimal) Application and points the facades at it during
 * setUp(), which is what makes `firefly.web.trace-id.*` observable at all; the same reason
 * packages/observability/tests/Logging/CorrelationIdLogProcessorTest.php is a testbench test. The keys are
 * read per call, not memoised, so setting them in the test body is enough — no boot-time seed needed,
 * unlike a #[ConditionalOnProperty].
 */
uses(TestCase::class);

/** A request carrying both ids: a valid W3C trace id on the attribute bag and a correlation id on the header. */
function tracedRequest(): Request
{
    $request = Request::create('/orders');
    $request->headers->set(CorrelationIdFilter::HEADER, 'corr-42');
    $request->attributes->set(TraceContext::TRACE_ID, '4bf92f3577b34da6a3ce929d0e0e4736');

    return $request;
}

it('reads the defaults from a real application when the keys are absent', function (): void {
    expect(TraceContext::header())->toBe(TraceContext::DEFAULT_HEADER)
        ->and(TraceContext::referenceFor(tracedRequest()))->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('falls back to the correlation id everywhere when firefly.web.trace-id.enabled is false', function (): void {
    config()->set(TraceContext::ENABLED_KEY, false);

    $request = tracedRequest();

    // The trace id is still READABLE — the kill switch turns off publishing it, not the reading of it, so
    // a log processor or an exchange row that wants it keeps working — but nothing a caller sees carries it.
    expect(TraceContext::traceId($request))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and(TraceContext::referenceFor($request))->toBe('corr-42')
        ->and(TraceContext::header())->toBe('');

    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), $request);
    /** @var array<string, mixed> $body */
    $body = json_decode((string) $response->getContent(), true);

    expect($body['traceId'])->toBe('corr-42')
        ->and($body['correlationId'])->toBe('corr-42')
        ->and($response->headers->has(TraceContext::DEFAULT_HEADER))->toBeFalse();
});

it('echoes the trace id on the header firefly.web.trace-id.header names', function (): void {
    config()->set(TraceContext::HEADER_KEY, 'X-Request-Trace');

    expect(TraceContext::header())->toBe('X-Request-Trace');

    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), tracedRequest());

    expect($response->headers->get('X-Request-Trace'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($response->headers->has(TraceContext::DEFAULT_HEADER))->toBeFalse();

    $filtered = (new CorrelationIdFilter)->handle(tracedRequest(), static fn (): Response => new Response('ok'));
    assert($filtered instanceof Response);

    expect($filtered->headers->get('X-Request-Trace'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($filtered->headers->has(TraceContext::DEFAULT_HEADER))->toBeFalse()
        // The correlation id is never moved onto the renamed header: two headers, two ids.
        ->and($filtered->headers->get(CorrelationIdFilter::HEADER))->toBe('corr-42');
});

it('writes no trace header at all when firefly.web.trace-id.header is empty', function (): void {
    config()->set(TraceContext::HEADER_KEY, '');

    expect(TraceContext::header())->toBe('');

    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), tracedRequest());

    $filtered = (new CorrelationIdFilter)->handle(tracedRequest(), static fn (): Response => new Response('ok'));
    assert($filtered instanceof Response);

    expect($response->headers->has(TraceContext::DEFAULT_HEADER))->toBeFalse()
        ->and($filtered->headers->has(TraceContext::DEFAULT_HEADER))->toBeFalse()
        // The echo is off; the document is untouched by it and still publishes the trace id as the reference.
        ->and(json_decode((string) $response->getContent(), true))->toMatchArray([
            'traceId' => '4bf92f3577b34da6a3ce929d0e0e4736',
            'correlationId' => 'corr-42',
        ])
        ->and($filtered->headers->get(CorrelationIdFilter::HEADER))->toBe('corr-42');
});
