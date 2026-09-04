<?php

declare(strict_types=1);

use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;
use Firefly\Observability\HttpExchanges\InMemoryHttpExchangeRecorder;
use Firefly\Observability\Tests\Support\ObservabilityCapstoneTestCase;

uses(ObservabilityCapstoneTestCase::class);

/**
 * The end-to-end proof, over the real HTTP kernel rather than a hand-built filter: an ordinary application
 * request passes through the #[Component] HttpExchangeFilter that web's FilterChainRegistrar discovered and
 * pushed onto the middleware stack, lands in the HttpExchangeRecorder that ObservabilityAutoConfiguration bound
 * as a #[Bean], and comes back out of the #[Lazy] HttpExchangesEndpoint that ActuatorRouteRegistrar resolved and
 * mounted — which only works at all because #[Lazy] kept the endpoint out of the EagerSingletons pass that runs
 * before the recorder bean exists.
 *
 * @return array<mixed>
 */
function capstoneExchangesBody(ObservabilityCapstoneTestCase $case): array
{
    /** @var array<mixed> $decoded */
    $decoded = json_decode($case->responseBody($case->getJson('/actuator/httpexchanges')), true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * @param  array<mixed>  $body
 * @return list<array<mixed>>
 */
function capstoneExchangeRows(array $body): array
{
    $value = $body['exchanges'] ?? null;
    if (! is_array($value)) {
        throw new RuntimeException('Expected [exchanges] to be an array.');
    }

    $rows = [];
    foreach ($value as $row) {
        if (! is_array($row)) {
            throw new RuntimeException('Expected each [exchanges] entry to be an array.');
        }
        $rows[] = $row;
    }

    return $rows;
}

it('records a real request through the discovered filter and serves it back from /actuator/httpexchanges', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $this->get('/demo/7')->assertStatus(200);

    $body = capstoneExchangesBody($this);
    $rows = capstoneExchangeRows($body);

    expect($rows)->toHaveCount(1)
        // The ROUTE TEMPLATE, not '/demo/7' — a busy endpoint must not be able to fill the whole ring with one
        // route's concrete paths.
        ->and($rows[0]['uri'])->toBe('/demo/{id}')
        ->and($rows[0]['method'])->toBe('GET')
        ->and($rows[0]['status'])->toBe(200)
        ->and($rows[0]['durationMs'])->toBeFloat()
        ->and($rows[0]['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/')
        // No bodies, ever; no headers unless capture is explicitly enabled.
        ->and(array_key_exists('requestHeaders', $rows[0]))->toBeFalse()
        ->and($rows[0])->not->toHaveKey('requestBody')
        ->and($rows[0])->not->toHaveKey('responseBody')
        ->and($body['recording'])->toBeTrue()
        ->and($body['storage'])->toBe('memory');
});

/**
 * The correlation id the framework already generates: web's CorrelationIdFilter is PREPENDED ahead of every
 * discovered WebFilter by FilterChainRegistrar, so it has already minted the id and written it back onto the
 * request by the time this filter reads it — which is why the filter reads the request header rather than the
 * Context copy, and why the recorded id is exactly the one echoed on the response.
 */
it('stamps each exchange with the same correlation id the framework echoes on the response', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $response = $this->get('/demo/9');
    $echoed = $response->headers->get('X-Correlation-Id');

    $rows = capstoneExchangeRows(capstoneExchangesBody($this));

    expect($echoed)->not->toBeNull()
        ->and($rows[0]['correlationId'])->toBe($echoed);
});

/**
 * A dashboard is a polling client: if its own /actuator/* requests were recorded, a panel refreshing every few
 * seconds would evict every genuine request from the ring and then show the operator nothing but their own
 * polling.
 */
it('keeps management traffic out of the buffer', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $this->getJson('/actuator/health')->assertStatus(200);
    $this->getJson('/actuator/httpexchanges')->assertStatus(200);

    expect(capstoneExchangeRows(capstoneExchangesBody($this)))->toBe([]);
});

it('reports newest-first ordering and the evicted history across several real requests', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    $this->get('/demo/1');
    $this->get('/demo/2');
    $this->get('/demo/3');

    $body = capstoneExchangesBody($this);

    expect($body['count'])->toBe(3)
        ->and($body['recorded'])->toBe(3)
        ->and($body['capacity'])->toBe(100);

    // ?limit trims the newest-first list so a ten-row panel fetches ten rows.
    /** @var array<mixed> $limited */
    $limited = json_decode($this->responseBody($this->getJson('/actuator/httpexchanges?limit=2')), true, 512, JSON_THROW_ON_ERROR);
    expect($limited['count'])->toBe(2);
});

it('binds the in-memory recorder by default and mounts /actuator/process alongside it', function () {
    /** @var ObservabilityCapstoneTestCase $this */
    expect($this->app()->make(HttpExchangeRecorder::class))->toBeInstanceOf(InMemoryHttpExchangeRecorder::class);

    $this->get('/demo/4');

    $this->getJson('/actuator/process')
        ->assertStatus(200)
        ->assertJsonPath('php.sapi', PHP_SAPI)
        ->assertJsonPath('requests.recorded', 1)
        ->assertJsonPath('requests.capacity', 100)
        ->assertJsonStructure(['pid', 'uptimeMs', 'php' => ['version', 'sapi'], 'memory' => ['usedBytes', 'peakBytes', 'limitBytes', 'limit'], 'requests']);
});
