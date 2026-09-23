<?php

declare(strict_types=1);

use Firefly\Observability\Tests\Support\TraceIdEverywhereCapstoneTestCase;
use Firefly\Web\Filter\CorrelationIdFilter;

uses(TraceIdEverywhereCapstoneTestCase::class);

it('puts the same W3C trace id on the problem document, the trace header and the log context', function () {
    /** @var TraceIdEverywhereCapstoneTestCase $this */
    $response = $this->getJson('/boom');

    $traceId = $response->json('traceId');

    expect($traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($response->headers->get('X-Trace-Id'))->toBe($traceId)
        ->and($response->json('correlationId'))->not->toBe($traceId)
        ->and($response->headers->get(CorrelationIdFilter::HEADER))->toBe($response->json('correlationId'));
});

it('continues an inbound traceparent, so the document publishes the CALLER\'s trace id', function () {
    /** @var TraceIdEverywhereCapstoneTestCase $this */
    $response = $this->getJson('/boom', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01']);

    expect($response->json('traceId'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736');
});

it('shows the trace id in the HTML page\'s Reference row', function () {
    /** @var TraceIdEverywhereCapstoneTestCase $this */
    $html = (string) $this->get('/boom', ['Accept' => 'text/html'])->getContent();

    expect($html)->toMatch('/Reference[\s\S]{0,200}[0-9a-f]{32}/');
});

/**
 * The same page, with the trace id made KNOWN by an inbound traceparent, so the assertion is on the exact
 * fact-row markup rather than on any 32-hex run the page happens to contain — and so the Correlation row
 * can be pinned beside it, which is the half of the design that says the two ids are not interchangeable.
 */
it('names the caller\'s trace id in the Reference row and the correlation id beside it', function () {
    /** @var TraceIdEverywhereCapstoneTestCase $this */
    $response = $this->withHeaders(['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])
        ->get('/boom', ['Accept' => 'text/html']);

    $correlationId = (string) $response->headers->get(CorrelationIdFilter::HEADER);
    $html = (string) $response->getContent();

    expect($correlationId)->not->toBe('')
        ->and($html)->toContain('<dt>Reference</dt><dd>4bf92f3577b34da6a3ce929d0e0e4736</dd>')
        ->toContain('<dt>Correlation</dt><dd>'.$correlationId.'</dd>');
});

/**
 * THE 200s, which nothing else here reaches — and which are the traffic of a running application.
 *
 * Every assertion above is on an error response, and on an error response the trace header is written by
 * ProblemDetailsRenderer itself: deleting CorrelationIdFilter's echo would leave all four of them green
 * while every successful response silently lost the header the documentation promises on it. So this asks
 * for an ordinary 200 through the same chain, with the trace id made KNOWN by an inbound `traceparent` so
 * the header is matched against a literal rather than against itself, and with a correlation id the CALLER
 * chose so the other half of the design — the trace id never overwrites it — is asserted rather than
 * assumed.
 */
it('echoes the trace id on an ordinary 200, beside the correlation id the caller sent', function () {
    /** @var TraceIdEverywhereCapstoneTestCase $this */
    $response = $this->withHeaders([
        'traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        CorrelationIdFilter::HEADER => 'caller-chose-this-one',
    ])->get('/demo/7');

    $response->assertOk();

    expect($response->headers->get('X-Trace-Id'))->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($response->headers->get(CorrelationIdFilter::HEADER))->toBe('caller-chose-this-one');
});
