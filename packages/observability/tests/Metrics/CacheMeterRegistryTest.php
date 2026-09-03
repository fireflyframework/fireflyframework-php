<?php

declare(strict_types=1);

use Firefly\Observability\Metrics\CacheMeterRegistry;
use Firefly\Observability\Metrics\Counter;
use Firefly\Observability\Metrics\Gauge;
use Firefly\Observability\Metrics\Timer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * The defect these pin: SimpleMeterRegistry holds meters in process memory, so under PHP-FPM — where every
 * request is a fresh process — a scrape of /actuator/metrics saw only what that scrape's own request had
 * recorded. The endpoints looked populated while reporting one request's numbers.
 *
 * "A second worker" is modelled as a SECOND registry over the SAME store, which is exactly what two PHP-FPM
 * processes are.
 */
function sharedStore(): Repository
{
    return new Repository(new ArrayStore);
}

/** meters() is typed list<Meter>; each assertion below knows which concrete meter it asked for. */
function onlyCounter(Repository $store): Counter
{
    $meters = (new CacheMeterRegistry($store))->meters();
    expect($meters)->toHaveCount(1)->and($meters[0])->toBeInstanceOf(Counter::class);
    assert($meters[0] instanceof Counter);

    return $meters[0];
}

it('accumulates counters across separate registry instances', function () {
    $store = sharedStore();

    (new CacheMeterRegistry($store))->increment('http.requests', ['route' => '/'], 3);
    (new CacheMeterRegistry($store))->increment('http.requests', ['route' => '/'], 2);

    $counter = onlyCounter($store);

    expect($counter->count())->toBe(5.0)
        ->and($counter->name())->toBe('http.requests')
        ->and($counter->tags())->toBe(['route' => '/']);
});

it('accumulates timer count and total duration across instances', function () {
    $store = sharedStore();

    (new CacheMeterRegistry($store))->record('http.latency', [], 0.100);
    (new CacheMeterRegistry($store))->record('http.latency', [], 0.300);

    $meters = (new CacheMeterRegistry($store))->meters();
    expect($meters[0])->toBeInstanceOf(Timer::class);
    assert($meters[0] instanceof Timer);

    expect($meters[0]->count())->toBe(2)
        ->and(round($meters[0]->totalTimeSeconds(), 6))->toBe(0.4);
});

it('treats a gauge as a snapshot — last writer wins', function () {
    $store = sharedStore();

    (new CacheMeterRegistry($store))->setGauge('queue.depth', [], 12.0);
    (new CacheMeterRegistry($store))->setGauge('queue.depth', [], 7.0);

    $meters = (new CacheMeterRegistry($store))->meters();
    expect($meters[0])->toBeInstanceOf(Gauge::class);
    assert($meters[0] instanceof Gauge);

    expect($meters[0]->value())->toBe(7.0);
});

it('keeps distinct tag sets as distinct meters', function () {
    $store = sharedStore();
    $registry = new CacheMeterRegistry($store);

    $registry->increment('http.requests', ['route' => '/a']);
    $registry->increment('http.requests', ['route' => '/b'], 4);

    $byTag = [];
    foreach ((new CacheMeterRegistry($store))->meters() as $meter) {
        expect($meter)->toBeInstanceOf(Counter::class);
        assert($meter instanceof Counter);
        $byTag[$meter->tags()['route']] = $meter->count();
    }

    expect($byTag)->toBe(['/a' => 1.0, '/b' => 4.0]);
});

it('records tag order-insensitively so the same meter is not split in two', function () {
    $store = sharedStore();

    (new CacheMeterRegistry($store))->increment('jobs', ['b' => '2', 'a' => '1']);
    (new CacheMeterRegistry($store))->increment('jobs', ['a' => '1', 'b' => '2']);

    expect(onlyCounter($store)->count())->toBe(2.0);
});

it('returns no meters before anything has been recorded', function () {
    expect((new CacheMeterRegistry(sharedStore()))->meters())->toBe([]);
});

it('still hands back live in-process meters from the factory methods', function () {
    $registry = new CacheMeterRegistry(sharedStore());

    $counter = $registry->counter('local', []);
    $counter->increment(2);

    expect($registry->counter('local', []))->toBe($counter)
        ->and($counter->count())->toBe(2.0);
});
