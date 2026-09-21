<?php

declare(strict_types=1);

use Firefly\Observability\Tests\Support\TracingCapstoneTestCase;
use Illuminate\Testing\TestResponse;
use OpenTelemetry\API\Trace\SpanKind as OtelSpanKind;
use Symfony\Component\HttpFoundation\Response;

uses(TracingCapstoneTestCase::class);

/**
 * A request handled INSIDE A FIBER, through the real HTTP kernel with the TracingFilter on — what the browser
 * suite's in-process AMP server and any Amp/ReactPHP application server do for every request. Before the tracer
 * initialised the fiber's OTel context itself, the first traced request in a fiber was a 500: the API raised
 * "Access to not initialized OpenTelemetry context in fiber" on the SERVER span's first context read, and
 * Laravel's handler turned that E_USER_WARNING into an ErrorException.
 */
it('serves a traced request inside a fiber with a SERVER span and the ids in Context, not a 500', function () {
    /** @var TracingCapstoneTestCase $this */
    $fiber = new Fiber(fn (): TestResponse => $this->getJson('/demo/7'));
    $fiber->start();
    /** @var TestResponse<Response> $response */
    $response = $fiber->getReturn();

    $response->assertStatus(200);
    $traceId = $response->json('traceId');

    $span = $this->spanNamed('GET /demo/{id}');
    expect($traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($span)->not->toBeNull()
        ->and($span?->getKind())->toBe(OtelSpanKind::KIND_SERVER)
        ->and($span?->getTraceId())->toBe($traceId)
        ->and($span?->getParentSpanId())->toBe('0000000000000000');
});

it('keeps each fiber\'s request on its own trace, with nothing left current on the main fiber', function () {
    /** @var TracingCapstoneTestCase $this */
    $traceIds = [];
    foreach ([1, 2] as $id) {
        $fiber = new Fiber(fn (): TestResponse => $this->getJson('/demo/'.$id));
        $fiber->start();
        /** @var TestResponse<Response> $response */
        $response = $fiber->getReturn();
        $response->assertStatus(200);
        $traceIds[] = $response->json('traceId');
    }

    expect($traceIds[0])->not->toBe($traceIds[1])
        ->and($this->getJson('/demo/3')->assertStatus(200)->json('traceId'))->not->toBeIn($traceIds);
});
