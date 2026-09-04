<?php

declare(strict_types=1);

use Firefly\Resilience\Bulkhead;
use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Store\CacheResilienceStore;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Firefly\Resilience\Tests\Fixtures\ContendedResilienceStore;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

it('rejects calls once max-concurrent permits are held and restores capacity on release', function () {
    $bulkhead = new Bulkhead('bh:test', new InMemoryResilienceStore, maxConcurrent: 2);

    expect($bulkhead->acquire())->toBeTrue()
        ->and($bulkhead->acquire())->toBeTrue()
        ->and(fn () => $bulkhead->call(fn (): string => 'blocked'))->toThrow(BulkheadFullException::class);

    $bulkhead->release();

    expect($bulkhead->call(fn (): string => 'ok'))->toBe('ok');
});

it('releases the permit even when the guarded callable throws', function () {
    $bulkhead = new Bulkhead('bh:throw', new InMemoryResilienceStore, maxConcurrent: 1);

    // The single permit is acquired, the callable throws, and release() must still run in finally.
    expect(fn () => $bulkhead->call(fn (): string => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    // If the throwing call had leaked its permit, the counter would stay at 1 and this would reject.
    expect($bulkhead->call(fn (): string => 'ok'))->toBe('ok');
});

it('shares the permit counter across separate store instances over one cache', function () {
    $cache = new Repository(new ArrayStore);
    $bulkheadA = new Bulkhead('bh:shared', new CacheResilienceStore($cache), maxConcurrent: 1);
    $bulkheadB = new Bulkhead('bh:shared', new CacheResilienceStore($cache), maxConcurrent: 1);

    expect($bulkheadA->acquire())->toBeTrue()
        ->and($bulkheadB->acquire())->toBeFalse(); // A holds the only permit, in the shared cache
});

it('reclaims a permit whose holder died, once the permit TTL elapses', function () {
    // The leak: acquire() used to be a bare atomic increment with no expiry, so a worker killed between
    // acquire() and release() (OOM, deploy SIGKILL, fatal error — no finally runs in a dead process)
    // subtracted one permit from the bulkhead PERMANENTLY. Enough crashes and max-concurrent permits are
    // all "held" by processes that no longer exist, and the bulkhead rejects 100% of traffic until an
    // operator flushes the cache by hand. Permits therefore carry a deadline and are pruned on read.
    $store = new InMemoryResilienceStore;
    $crashed = new Bulkhead('bh:crash', $store, maxConcurrent: 1, permitTtl: 0.05);
    $survivor = new Bulkhead('bh:crash', $store, maxConcurrent: 1, permitTtl: 0.05);

    expect($crashed->acquire())->toBeTrue()   // this holder never releases: it "dies" here
        ->and($survivor->acquire())->toBeFalse(); // still within the TTL, the permit is legitimately held

    usleep(80_000);

    expect($survivor->acquire())->toBeTrue(); // the dead holder's permit has expired and is reclaimed
});

it('ignores a release() that this holder never matched with an acquire()', function () {
    // The old decrement-based counter let a stray release() push the shared counter NEGATIVE, manufacturing
    // capacity out of nothing: two unmatched releases on a max-concurrent-1 bulkhead left it at -2, and the
    // next TWO callers both slipped past the limit. A release is now the return of a permit this instance
    // actually holds, so an unmatched one is a no-op.
    $store = new InMemoryResilienceStore;
    $bulkhead = new Bulkhead('bh:unmatched', $store, maxConcurrent: 1);

    $bulkhead->release();
    $bulkhead->release();

    expect($bulkhead->acquire())->toBeTrue()
        ->and((new Bulkhead('bh:unmatched', $store, maxConcurrent: 1))->acquire())->toBeFalse();
});

it('never lets the permit return destroy the guarded call\'s own result or exception', function () {
    // call() returns the permit in a finally. That cleanup now goes through the store mutex, and the store
    // is fail-fast: a contended lock raises ServiceUnavailableException. A throw out of a finally REPLACES
    // whatever the block was about to produce, so an unguarded release() would turn a perfectly successful
    // call into a 503, and would swap a caller's own DomainException for an unrelated infrastructure one —
    // the guarded work would already have happened, and its outcome would be lost. Returning the permit is
    // best effort: the lease deadline is the backstop that makes it safe to give up.
    $store = new ContendedResilienceStore(failFromCall: 2); // the claim succeeds, the release cannot

    expect((new Bulkhead('bh:cleanup', $store, maxConcurrent: 1))->call(fn (): string => 'ok'))->toBe('ok');

    $store = new ContendedResilienceStore(failFromCall: 2);

    expect(fn () => (new Bulkhead('bh:cleanup', $store, maxConcurrent: 1))->call(
        fn () => throw new DomainException('the caller\'s own failure'),
    ))->toThrow(DomainException::class, 'the caller\'s own failure');
});
