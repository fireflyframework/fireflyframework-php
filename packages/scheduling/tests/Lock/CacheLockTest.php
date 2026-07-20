<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Scheduling\Lock\CacheLock;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Lock\NoneLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;

it('grants a name to one holder and refuses a second until release', function () {
    // ONE ArrayStore, TWO Repository wrappers => genuine cross-instance contention: ArrayLock state lives on the
    // store, and each CacheLock mints its own owner token, so the second holder is a foreign owner.
    $store = new ArrayStore;
    $first = new CacheLock(new Repository($store));
    $second = new CacheLock(new Repository($store));

    expect($first)->toBeInstanceOf(DistributedLock::class)
        ->and($first->tryAcquire('reports', 30.0))->toBeTrue()
        ->and($second->tryAcquire('reports', 30.0))->toBeFalse(); // held by $first

    $first->release('reports');

    expect($second->tryAcquire('reports', 30.0))->toBeTrue(); // freed, the foreign owner may now take it
});

it('treats release of a name it never acquired as a no-op', function () {
    $lock = new CacheLock(new Repository(new ArrayStore));

    $lock->release('never-held'); // must not throw

    expect($lock->tryAcquire('job', 5.0))->toBeTrue();
});

it('rejects a cache store that cannot provide locks', function () {
    // A bare Store that is NOT a LockProvider — configuring provider=cache over it must fail loudly with a clear
    // ConfigurationException rather than a fatal Error. (NullStore/array/database/redis all DO implement
    // LockProvider in Laravel 13, so this guard needs a store double that genuinely lacks lock capability.)
    $store = new class implements Store
    {
        public function get($key)
        {
            return null;
        }

        /**
         * @param  array<int, string>  $keys
         * @return array<string, mixed>
         */
        public function many(array $keys)
        {
            return [];
        }

        public function put($key, $value, $seconds)
        {
            return true;
        }

        /** @param array<string, mixed> $values */
        public function putMany(array $values, $seconds)
        {
            return true;
        }

        /** @return int */
        public function increment($key, $value = 1)
        {
            return 1;
        }

        /** @return int */
        public function decrement($key, $value = 1)
        {
            return 1;
        }

        public function forever($key, $value)
        {
            return true;
        }

        public function touch($key, $seconds)
        {
            return true;
        }

        public function forget($key)
        {
            return true;
        }

        public function flush()
        {
            return true;
        }

        public function getPrefix()
        {
            return '';
        }
    };

    $lock = new CacheLock(new Repository($store));

    expect(fn () => $lock->tryAcquire('job', 5.0))->toThrow(ConfigurationException::class, 'atomic locks');
});

it('contrast: NoneLock never contends', function () {
    $lock = new NoneLock;

    expect($lock->tryAcquire('job', 5.0))->toBeTrue()
        ->and($lock->tryAcquire('job', 5.0))->toBeTrue();
});
