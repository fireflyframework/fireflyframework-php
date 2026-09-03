<?php

declare(strict_types=1);

use Firefly\Actuator\Endpoint\EndpointRequest;
use Firefly\Config\Config;
use Firefly\Observability\Endpoint\HttpExchangesEndpoint;
use Firefly\Observability\HttpExchanges\CacheHttpExchangeRecorder;
use Firefly\Observability\HttpExchanges\HttpExchange;
use Firefly\Observability\HttpExchanges\HttpExchangeRecorder;
use Firefly\Observability\HttpExchanges\InMemoryHttpExchangeRecorder;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;

/**
 * EndpointResponse::$body is `array<mixed>|string`; narrow it through real control flow rather than a
 * suppressing type-override docblock (the actuator introspectionJsonBody() idiom). Named distinctly so the file
 * has no top-level-function collision when Pest loads the whole suite into one process.
 *
 * @param  array<string, mixed>  $firefly
 * @param  array<string, mixed>  $query
 * @return array<mixed>
 */
function httpExchangesBody(HttpExchangeRecorder $recorder, array $firefly = [], array $query = []): array
{
    $endpoint = new HttpExchangesEndpoint($recorder, new Config(new ConfigRepository($firefly)));
    $body = $endpoint->handle(new EndpointRequest('GET', [], $query))->body;

    if (! is_array($body)) {
        throw new RuntimeException('Expected a JSON (array) response body.');
    }

    return $body;
}

function httpExchangesFixture(string $uri, int $status, string $timestamp): HttpExchange
{
    return new HttpExchange($timestamp, 'GET', $uri, $status, 4.25, 'corr-'.$status);
}

/** The payload shape a dashboard renders, asserted verbatim. */
it('serves the buffered exchanges newest-first with the storage model that explains them', function () {
    $recorder = new InMemoryHttpExchangeRecorder(50);
    $recorder->record(httpExchangesFixture('/users/{id}', 200, '2026-09-03T10:00:00.000001Z'));
    $recorder->record(httpExchangesFixture('/orders', 422, '2026-09-03T10:00:01.000002Z'));

    expect(httpExchangesBody($recorder))->toBe([
        'exchanges' => [
            [
                'timestamp' => '2026-09-03T10:00:01.000002Z',
                'method' => 'GET',
                'uri' => '/orders',
                'status' => 422,
                'durationMs' => 4.25,
                'correlationId' => 'corr-422',
            ],
            [
                'timestamp' => '2026-09-03T10:00:00.000001Z',
                'method' => 'GET',
                'uri' => '/users/{id}',
                'status' => 200,
                'durationMs' => 4.25,
                'correlationId' => 'corr-200',
            ],
        ],
        'count' => 2,
        'capacity' => 50,
        'recorded' => 2,
        'storage' => 'memory',
        'processLocal' => true,
        'recording' => true,
    ]);
});

/**
 * The reason this endpoint carries five fields Spring's does not. Under PHP-FPM the default recorder can only
 * ever answer with an empty list — each request is a fresh process, and the request rendering this endpoint has
 * not been recorded yet because the filter records on the way out. An operator handed `{"exchanges": []}` and
 * nothing else concludes their application is serving no traffic and goes hunting a routing bug that does not
 * exist. `storage`/`processLocal` name the fix; `recording` names the flag.
 */
it('says WHY the buffer is empty rather than leaving an operator to guess', function () {
    $processLocal = httpExchangesBody(new InMemoryHttpExchangeRecorder(50));

    expect($processLocal)->toBe([
        'exchanges' => [],
        'count' => 0,
        'capacity' => 50,
        'recorded' => 0,
        'storage' => 'memory',
        'processLocal' => true,
        'recording' => true,
    ]);

    $crossProcess = httpExchangesBody(
        new CacheHttpExchangeRecorder(new CacheRepository(new ArrayStore), 'redis', 50),
        ['firefly.observability.httpexchanges.enabled' => false],
    );

    expect($crossProcess['storage'])->toBe('cache:redis')
        ->and($crossProcess['processLocal'])->toBeFalse()
        // Recording switched off: the endpoint stays mounted precisely so it can say so. An absent endpoint
        // would be a 404 that tells the operator nothing.
        ->and($crossProcess['recording'])->toBeFalse();
});

/**
 * `recorded` is monotonic and NOT capped at capacity, so `recorded - count` is how much history the ring has
 * already evicted — the number behind "showing the last 2 of 5".
 */
it('reports the evicted history through the monotonic recorded total', function () {
    $recorder = new InMemoryHttpExchangeRecorder(2);
    foreach (['/a', '/b', '/c', '/d', '/e'] as $uri) {
        $recorder->record(httpExchangesFixture($uri, 200, '2026-09-03T10:00:00.000001Z'));
    }

    $body = httpExchangesBody($recorder);

    expect($body['count'])->toBe(2)
        ->and($body['recorded'])->toBe(5)
        ->and($body['capacity'])->toBe(2);
});

it('trims the newest-first list to ?limit=N and ignores a malformed limit instead of answering 400', function () {
    $recorder = new InMemoryHttpExchangeRecorder(50);
    foreach (['/a', '/b', '/c'] as $uri) {
        $recorder->record(httpExchangesFixture($uri, 200, '2026-09-03T10:00:00.000001Z'));
    }

    $limited = httpExchangesBody($recorder, [], ['limit' => '2']);
    expect($limited['count'])->toBe(2);

    // A management endpoint answering 400 to a cosmetic client bug turns it into a blank panel, and there is an
    // obvious correct answer available: the unfiltered list.
    foreach (['nonsense', '0', '-3', ''] as $bad) {
        expect(httpExchangesBody($recorder, [], ['limit' => $bad])['count'])->toBe(3);
    }
});

/**
 * `recording` must equal what the FILTER did, not what a permissive bool cast thinks the flag means.
 * HttpExchangeFilter is gated by #[ConditionalOnProperty(havingValue: 'true')], and ConditionEvaluator compares
 * the stringified value against that literal — so `enabled = 1` (the shape `env('FIREFLY_...')` hands back for
 * `FIREFLY_...=1`) drops the filter and records nothing. Reported through Config::bool() that answered
 * `"recording": true` next to a permanently empty list, which is the precise dead end this field exists to
 * prevent: an operator concluding the application serves no traffic.
 */
it('reports recording exactly as the filter condition reads the flag, not as a loose bool cast', function () {
    $recorder = new InMemoryHttpExchangeRecorder(10);

    foreach ([1, '1', 'on', 'yes', 0, 'false', false] as $off) {
        expect(httpExchangesBody($recorder, ['firefly.observability.httpexchanges.enabled' => $off])['recording'])
            ->toBeFalse();
    }

    foreach ([true, 'true'] as $on) {
        expect(httpExchangesBody($recorder, ['firefly.observability.httpexchanges.enabled' => $on])['recording'])
            ->toBeTrue();
    }

    // Absent flag: the filter's matchIfMissing=true default is on, so recording is on.
    expect(httpExchangesBody($recorder)['recording'])->toBeTrue();
});
