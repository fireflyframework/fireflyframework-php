<?php

declare(strict_types=1);

use Firefly\Scheduling\Lock\CacheLock;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Predis\Client;

uses(FireflyTestCase::class);

/**
 * lock.provider=cache over a REAL Redis store — the multi-node configuration scheduling.md recommends, and the one
 * cache driver whose atomic lock CacheLock's own unit tests (array store, one process) cannot exercise: two owners
 * excluded, release by the owner only, and the TTL ending a lease nobody released.
 */
it('excludes, releases and expires CacheLock leases on a real Redis', function () {
    config([
        'database.redis.client' => 'predis',
        'database.redis.default' => ['url' => IntegrationDsn::value(IntegrationDsn::REDIS)],
        'cache.stores.it_redis' => ['driver' => 'redis', 'connection' => 'default', 'lock_connection' => 'default'],
    ]);
    /** @var Repository $store */
    $store = Cache::store('it_redis');
    $a = new CacheLock($store, 'owner-a');
    $b = new CacheLock($store, 'owner-b');
    $name = 'firefly:test:'.bin2hex(random_bytes(4));

    expect($a->tryAcquire($name, 30))->toBeTrue()
        ->and($b->tryAcquire($name, 30))->toBeFalse();
    $b->release($name);
    $redis = $store->getStore();
    if (! $redis instanceof LockProvider) {
        throw new RuntimeException('The Redis cache store is expected to provide atomic locks.');
    }
    // Owner tokens are enforced by Redis itself, not only by CacheLock's bookkeeping: a foreign token releases nothing.
    expect($b->tryAcquire($name, 30))->toBeFalse()
        ->and($redis->lock($name, 30, 'owner-b')->release())->toBeFalse()
        ->and($b->tryAcquire($name, 30))->toBeFalse();
    $a->release($name);
    expect($b->tryAcquire($name, 1))->toBeTrue();

    usleep(1_300_000);
    expect($a->tryAcquire($name, 30))->toBeTrue();
    $a->release($name);
})->group('integration')->skip(
    IntegrationDsn::missing(IntegrationDsn::REDIS) || ! class_exists(Client::class),
    'Set FIREFLY_REDIS_URL (and install predis/predis) to run the Redis CacheLock integration test.',
);
