<?php

declare(strict_types=1);

use Firefly\Observability\HttpExchanges\CacheHttpExchangeRecorder;
use Firefly\Observability\HttpExchanges\HttpExchange;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * The defect these pin, and it is sharper than the one CacheMeterRegistry was written for. Under PHP-FPM every
 * request is a fresh process: an in-memory ring is created empty, the filter appends the current request to it
 * AFTER the response is generated, and the process dies. The process that later renders
 * /actuator/httpexchanges is a different one holding a different empty ring — and its own request has not been
 * recorded yet. So the in-memory recorder under PHP-FPM answers with ZERO exchanges, on every call, forever.
 *
 * "A second worker" is modelled the way CacheMeterRegistryTest models it — a SECOND recorder over the SAME
 * store, which is exactly what two PHP-FPM processes are.
 */
function exchangeStore(): Repository
{
    return new Repository(new ArrayStore);
}

function cachedExchange(string $uri, int $status = 200): HttpExchange
{
    return new HttpExchange('2026-09-03T10:11:12.131415Z', 'GET', $uri, $status, 1.5, 'corr-'.$uri);
}

/** @return list<string> */
function cachedUris(CacheHttpExchangeRecorder $recorder): array
{
    return array_map(static fn (HttpExchange $e): string => $e->uri, $recorder->exchanges());
}

it('lets one worker read the exchanges another worker recorded, newest first', function () {
    $store = exchangeStore();

    (new CacheHttpExchangeRecorder($store, 'redis'))->record(cachedExchange('/first'));
    (new CacheHttpExchangeRecorder($store, 'redis'))->record(cachedExchange('/second'));

    // A THIRD process — the one rendering the endpoint — sees both, which is the entire point.
    expect(cachedUris(new CacheHttpExchangeRecorder($store, 'redis')))->toBe(['/second', '/first'])
        ->and((new CacheHttpExchangeRecorder($store, 'redis'))->recorded())->toBe(2);
});

it('rolls over the ring, keeping the newest capacity exchanges across processes', function () {
    $store = exchangeStore();

    foreach (['/a', '/b', '/c', '/d'] as $uri) {
        (new CacheHttpExchangeRecorder($store, 'redis', 2))->record(cachedExchange($uri));
    }

    $reader = new CacheHttpExchangeRecorder($store, 'redis', 2);

    expect(cachedUris($reader))->toBe(['/d', '/c'])
        ->and($reader->recorded())->toBe(4)
        ->and($reader->capacity())->toBe(2);
});

it('preserves the full row across the store round trip', function () {
    $store = exchangeStore();

    (new CacheHttpExchangeRecorder($store, 'redis'))->record(
        new HttpExchange('2026-09-03T10:11:12.131415Z', 'POST', '/orders/{id}', 422, 33.75, 'corr-77', ['accept' => 'application/json'])
    );

    $exchanges = (new CacheHttpExchangeRecorder($store, 'redis'))->exchanges();

    expect($exchanges)->toHaveCount(1)
        ->and($exchanges[0]->toArray())->toBe([
            'timestamp' => '2026-09-03T10:11:12.131415Z',
            'method' => 'POST',
            'uri' => '/orders/{id}',
            'status' => 422,
            'durationMs' => 33.75,
            'correlationId' => 'corr-77',
            'requestHeaders' => ['accept' => 'application/json'],
        ]);
});

/**
 * The stale-write guard (documented imprecision 1 on the class): a writer that stalls between taking its
 * ordinal and writing its row must not be able to overwrite a NEWER row that landed in the same slot. Modelled
 * by writing the later ordinal first, then replaying the straggler — which, with capacity 1, targets the same
 * slot.
 */
it('refuses to let a straggling writer overwrite a newer row in the same slot', function () {
    $store = exchangeStore();

    $recorder = new CacheHttpExchangeRecorder($store, 'redis', 1);
    $recorder->record(cachedExchange('/older'));   // ordinal 1 -> slot 0
    $recorder->record(cachedExchange('/newest'));  // ordinal 2 -> slot 0 again; ordinary ring rollover

    // Rewind the shared sequence so the next write takes ordinal 1 again — BELOW the ordinal 2 already sitting
    // in that slot. That is the in-process equivalent of a worker that stalled between taking its ordinal and
    // writing its row, and being overtaken by a later one.
    $store->put('firefly:httpexchanges:seq', 0);
    $recorder->record(cachedExchange('/straggler'));

    expect(cachedUris(new CacheHttpExchangeRecorder($store, 'redis', 1)))->toBe(['/newest']);
});

/**
 * A shared cache store is not a private data structure. Anything under a colliding key must cost one row, not
 * the whole endpoint.
 */
it('skips rows the store hands back that are not exchanges', function () {
    $store = exchangeStore();

    // Ordinals start at 1 (the first increment of an absent counter yields 1), so with capacity 3 these two
    // rows land in slots 1 and 2 and slot 0 is free to be poisoned with something no one here wrote.
    (new CacheHttpExchangeRecorder($store, 'redis', 3))->record(cachedExchange('/real-a'));
    (new CacheHttpExchangeRecorder($store, 'redis', 3))->record(cachedExchange('/real-b'));
    $store->put('firefly:httpexchanges:slot:0', 'someone else was here');

    expect(cachedUris(new CacheHttpExchangeRecorder($store, 'redis', 3)))->toBe(['/real-b', '/real-a']);

    // ...and a row that IS an array but is not shaped like an exchange is dropped the same way, rather than
    // fataling the endpoint that reads it.
    $store->put('firefly:httpexchanges:slot:0', ['seq' => 99, 'exchange' => ['not' => 'an exchange']]);

    expect(cachedUris(new CacheHttpExchangeRecorder($store, 'redis', 3)))->toBe(['/real-b', '/real-a']);
});

it('names the store it is backed by and declares itself cross-process', function () {
    $recorder = new CacheHttpExchangeRecorder(exchangeStore(), 'redis');

    expect($recorder->storage())->toBe('cache:redis')
        ->and($recorder->processLocal())->toBeFalse();
});
