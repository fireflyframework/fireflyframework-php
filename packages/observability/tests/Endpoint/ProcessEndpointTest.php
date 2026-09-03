<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Observability\Endpoint\ProcessEndpoint;
use Firefly\Observability\HttpExchanges\HttpExchange;
use Firefly\Observability\HttpExchanges\InMemoryHttpExchangeRecorder;
use Firefly\Observability\Process\RuntimeSnapshot;

/**
 * Narrows EndpointResponse::$body (`array<mixed>|string`) through real control flow rather than a suppressing
 * override, and named distinctly so the file has no top-level-function collision across the Pest suite.
 *
 * @return array<mixed>
 */
function processEndpointBody(ProcessEndpoint $endpoint): array
{
    $body = $endpoint->handle(new EndpointRequest('GET', []))->body;

    if (! is_array($body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $body;
}

/**
 * @param  array<mixed>  $body
 * @return array<mixed>
 */
function processEndpointSection(array $body, string $key): array
{
    $value = $body[$key] ?? null;
    if (! is_array($value)) {
        throw new RuntimeException("Expected [{$key}] to be an array.");
    }

    return $value;
}

it('reports the live runtime numbers a dashboard plots, in a fixed top-level shape', function () {
    $recorder = new InMemoryHttpExchangeRecorder(25);
    $recorder->record(new HttpExchange('2026-09-03T10:00:00.000001Z', 'GET', '/x', 200, 1.0, null));
    $recorder->record(new HttpExchange('2026-09-03T10:00:01.000002Z', 'GET', '/y', 200, 1.0, null));

    $body = processEndpointBody(new ProcessEndpoint($recorder, new RuntimeSnapshot, microtime(true) - 1.5));

    expect(array_keys($body))->toBe(['pid', 'uptimeMs', 'php', 'memory', 'opcache', 'requests'])
        ->and($body['pid'])->toBe(getmypid())
        // uptimeMs is measured from the construction of the bean — genuine worker uptime under Octane, the age
        // of the current request under PHP-FPM. Injected here so the assertion is deterministic.
        ->and($body['uptimeMs'])->toBeGreaterThanOrEqual(1500.0)
        ->and(processEndpointSection($body, 'php'))->toBe(['version' => PHP_VERSION, 'sapi' => PHP_SAPI])
        ->and(array_keys(processEndpointSection($body, 'memory')))->toBe(['usedBytes', 'peakBytes', 'limitBytes', 'limit'])
        // The request count the framework was ALREADY keeping, not a second counter invented for this endpoint.
        // `buffered` is deliberately absent: answering it means a capacity-wide multi-get on the cache-backed
        // recorder, and this is the endpoint a dashboard polls to plot memory over time.
        ->and(processEndpointSection($body, 'requests'))->toBe(['recorded' => 2, 'capacity' => 25]);
});

it('reports opcache as null rather than 500ing on a machine where the API is unavailable', function () {
    $body = processEndpointBody(new ProcessEndpoint(new InMemoryHttpExchangeRecorder));

    // Either shape is legitimate — what must never happen is the endpoint throwing because opcache is absent,
    // disabled, or locked down by opcache.restrict_api (which emits an E_WARNING that Laravel's error handler
    // turns into an ErrorException). A hardened production box is exactly where an operator opens this.
    expect(array_key_exists('opcache', $body))->toBeTrue()
        ->and($body['opcache'] === null || is_array($body['opcache']))->toBeTrue();
});

it('keeps the payload non-sensitive: no environment, no include path, no extension inventory', function () {
    $body = processEndpointBody(new ProcessEndpoint(new InMemoryHttpExchangeRecorder));

    expect($body)->not->toHaveKey('env')
        ->and($body)->not->toHaveKey('extensions')
        ->and($body)->not->toHaveKey('includePath')
        ->and(processEndpointSection($body, 'php'))->toBe(['version' => PHP_VERSION, 'sapi' => PHP_SAPI]);
});
