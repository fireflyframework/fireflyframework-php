<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
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

it('fails fast on a contended lock and maps the timeout to a 503 instead of a raw LockTimeoutException', function () {
    // The defect: withLock() passed the SAME number as both the lock TTL and the blocking wait, and every
    // caller passed 5.0 — so a fail-fast rate limiter would sit for five seconds on a contended key and then
    // throw Illuminate's LockTimeoutException, which no framework mapper knows about, so it surfaced as a
    // bare HTTP 500. Waiting is now a separate, configurable budget and the timeout is a first-class 503
    // (a kernel infrastructure exception the framework's error mapper already renders correctly).
    $arrayStore = new ArrayStore;
    $store = new CacheResilienceStore(new Repository($arrayStore), lockBlockTimeout: 0.1);

    // A different owner holds the mutex for the next ten seconds: withLock() cannot possibly get it.
    expect($arrayStore->lock('rl:contended:lock', 10)->get())->toBeTrue();

    $started = microtime(true);
    $thrown = null;
    try {
        $store->withLock('rl:contended', 5.0, static fn (): string => 'unreached');
    } catch (Throwable $e) {
        $thrown = $e;
    }
    $elapsed = microtime(true) - $started;

    expect($thrown)->toBeInstanceOf(ServiceUnavailableException::class);
    assert($thrown instanceof ServiceUnavailableException);

    expect($thrown->httpStatus())->toBe(503)
        ->and($thrown->errorCode())->toBe('RESILIENCE_STORE_LOCK_TIMEOUT')
        ->and($thrown->getMessage())->toContain('rl:contended')
        ->and($elapsed)->toBeLessThan(1.0); // the old hardcoded blocking wait was five whole seconds
});

it('honours a zero lock-block timeout as a single non-blocking attempt', function () {
    $arrayStore = new ArrayStore;
    $store = new CacheResilienceStore(new Repository($arrayStore), lockBlockTimeout: 0.0);

    expect($arrayStore->lock('nb:lock', 10)->get())->toBeTrue();

    $started = microtime(true);
    expect(fn () => $store->withLock('nb', 5.0, static fn (): string => 'unreached'))
        ->toThrow(ServiceUnavailableException::class);

    expect(microtime(true) - $started)->toBeLessThan(0.2);
});

it('releases the lock after the critical section so the next caller is admitted', function () {
    $store = new CacheResilienceStore(new Repository(new ArrayStore), lockBlockTimeout: 0.1);

    expect($store->withLock('serial', 5.0, static fn (): int => 1))->toBe(1)
        ->and($store->withLock('serial', 5.0, static fn (): int => 2))->toBe(2);
});

it('releases the lock even when the critical section throws', function () {
    $store = new CacheResilienceStore(new Repository(new ArrayStore), lockBlockTimeout: 0.1);

    expect(fn () => $store->withLock('boom', 5.0, static fn () => throw new RuntimeException('inside')))
        ->toThrow(RuntimeException::class);

    // A leaked mutex here would make every later transition on this key throw a 503 until the TTL expired.
    expect($store->withLock('boom', 5.0, static fn (): string => 'ok'))->toBe('ok');
});
