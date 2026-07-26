<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Actuator\Endpoint\EndpointResponse;
use Firefly\Observability\Endpoint\MetricsEndpoint;
use Firefly\Observability\Endpoint\PrometheusEndpoint;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\Prometheus\PrometheusTextFormat;

/**
 * EndpointResponse::$body is `array<mixed>|string` and MetricsEndpoint::handle() is genuinely nullable
 * (null signals 404 on an unknown metric name) — narrow via real control flow (never a suppressing
 * type-override docblock annotation), the same idiom as actuator's
 * introspectionJsonBody()/mlsJsonBody() helpers. Named distinctly so this file has no
 * top-level-function collision when Pest loads the whole suite into one process.
 *
 * @return array<mixed>
 */
function observabilityMetricsJsonBody(?EndpointResponse $response): array
{
    if ($response === null) {
        throw new RuntimeException('Expected a non-null EndpointResponse.');
    }

    if (! is_array($response->body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $response->body;
}

/**
 * Narrows one `array<mixed>` value under $key to a `list<array<mixed>>` via real runtime checks (not a
 * suppressing override) — mirrors actuator's introspectionRows() helper, so PHPStan sees a real array
 * type (offset-accessible) at every row rather than a bare `mixed` that a numeric offset would reject.
 *
 * @param  array<mixed>  $body
 * @return list<array<mixed>>
 */
function observabilityMetricsRows(array $body, string $key): array
{
    $value = $body[$key] ?? null;
    if (! is_array($value)) {
        throw new RuntimeException("Expected [{$key}] to be an array.");
    }

    $rows = [];
    foreach ($value as $row) {
        if (! is_array($row)) {
            throw new RuntimeException("Expected each [{$key}] entry to be an array.");
        }
        $rows[] = $row;
    }

    return $rows;
}

it('serves prometheus text with the 0.0.4 content type', function () {
    $registry = new SimpleMeterRegistry;
    $registry->counter('up')->increment();

    $response = (new PrometheusEndpoint($registry, new PrometheusTextFormat))->handle(new EndpointRequest('GET', []));

    expect($response->contentType)->toBe('text/plain; version=0.0.4')
        ->and($response->body)->toContain('up 1');
});

it('lists metric names on /metrics and a single metric detail on /metrics/{name}', function () {
    $registry = new SimpleMeterRegistry;
    $registry->counter('errors_total', ['kind' => 'io'])->increment(2.0);

    $endpoint = new MetricsEndpoint($registry);

    $names = observabilityMetricsJsonBody($endpoint->handle(new EndpointRequest('GET', [])));
    expect($names['names'])->toContain('errors_total');

    $detail = observabilityMetricsJsonBody($endpoint->handle(new EndpointRequest('GET', ['errors_total'])));
    $measurements = observabilityMetricsRows($detail, 'measurements');
    expect($detail['name'])->toBe('errors_total')
        ->and($measurements[0])->toBe(['statistic' => 'COUNT', 'value' => 2.0])
        ->and($detail['availableTags'])->toBe([['tag' => 'kind', 'values' => ['io']]]);
});

it('404s an unknown metric name', function () {
    $endpoint = new MetricsEndpoint(new SimpleMeterRegistry);

    expect($endpoint->handle(new EndpointRequest('GET', ['nope'])))->toBeNull();
});
