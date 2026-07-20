<?php

declare(strict_types=1);

use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('increments atomically from zero and decrements over the real cache', function () {
    $store = new CacheResilienceStore(new Repository(new ArrayStore));

    expect($store->increment('c'))->toBe(1)
        ->and($store->increment('c', 2))->toBe(3)
        ->and($store->decrement('c'))->toBe(2);
});

it('add returns true only for the first writer of a key (add-if-absent) across two store instances over one cache', function () {
    $cache = new Repository(new ArrayStore);
    $a = new CacheResilienceStore($cache);
    $b = new CacheResilienceStore($cache); // a DIFFERENT store object over the SAME cache

    expect($a->add('k', 'first'))->toBeTrue()
        ->and($b->add('k', 'second'))->toBeFalse()
        ->and($b->get('k'))->toBe('first');
});

it('runs a critical section under withLock and returns its result', function () {
    $store = new CacheResilienceStore(new Repository(new ArrayStore));

    expect($store->withLock('cs', 1.0, static fn (): int => 42))->toBe(42);
});

it('forget removes a key', function () {
    $store = new CacheResilienceStore(new Repository(new ArrayStore));
    $store->put('k', 'v');
    $store->forget('k');

    expect($store->get('k'))->toBeNull();
});

it('InMemoryResilienceStore mirrors the atomic contract for single-process use', function () {
    $store = new InMemoryResilienceStore;

    expect($store->add('k', 1))->toBeTrue()
        ->and($store->add('k', 2))->toBeFalse()
        ->and($store->increment('n'))->toBe(1)
        ->and($store->increment('n'))->toBe(2)
        ->and($store->decrement('n'))->toBe(1)
        ->and($store->withLock('x', 1.0, static fn (): string => 'ok'))->toBe('ok');
});
