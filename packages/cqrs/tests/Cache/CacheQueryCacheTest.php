<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\GroupedQueryCache;
use Firefly\Cqrs\Tests\Support\RecordingLogger;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

function w4QueryCacheOnDatabase(): Repository
{
    $capsule = new Capsule;
    $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    $connection = $capsule->getConnection();
    $connection->getSchemaBuilder()->create('cache', static function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });
    $connection->getSchemaBuilder()->create('cache_locks', static function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });

    return new Repository(new DatabaseStore($connection, 'cache'));
}

function w4QueryCacheRepository(string $store): Repository
{
    return $store === 'database' ? w4QueryCacheOnDatabase() : new Repository(new ArrayStore);
}

dataset('w4 query cache stores', [
    'array store' => ['array'],
    'database store' => ['database'],
]);

afterEach(function () {
    Carbon::setTestNow();
});

it('is a grouped query cache that misses, then hits what it stored', function (string $store) {
    $cache = new CacheQueryCache(w4QueryCacheRepository($store));

    expect($cache)->toBeInstanceOf(GroupedQueryCache::class)
        ->and($cache->lookup('balances', 'k1'))->toBeNull();

    $cache->store('balances', 'k1', ['balance' => 10], 60);

    expect($cache->lookup('balances', 'k1'))->toBe(['balance' => 10])
        ->and($cache->lookup('balances', 'k2'))->toBeNull()
        ->and($cache->lookup('other', 'k1'))->toBeNull();
})->with('w4 query cache stores');

it('evicts one key without touching its neighbours', function (string $store) {
    $cache = new CacheQueryCache(w4QueryCacheRepository($store));
    $cache->store('balances', 'k1', 1, 60);
    $cache->store('balances', 'k2', 2, 60);

    $cache->evictKey('balances', 'k1');

    expect($cache->lookup('balances', 'k1'))->toBeNull()
        ->and($cache->lookup('balances', 'k2'))->toBe(2);
})->with('w4 query cache stores');

it('evicts a whole group by starting a new generation, and leaves other groups alone', function (string $store) {
    $cache = new CacheQueryCache(w4QueryCacheRepository($store));
    $cache->store('balances', 'k1', 1, 60);
    $cache->store('balances', 'k2', 2, 60);
    $cache->store('totals', 'all', 3, 60);

    $cache->evictGroup('balances');

    expect($cache->lookup('balances', 'k1'))->toBeNull()
        ->and($cache->lookup('balances', 'k2'))->toBeNull()
        ->and($cache->lookup('totals', 'all'))->toBe(3);

    $cache->store('balances', 'k1', 10, 60);

    expect($cache->lookup('balances', 'k1'))->toBe(10);
})->with('w4 query cache stores');

it('drops a store that looked the group up before an eviction, so the stale result is never cached', function (string $store) {
    $cache = new CacheQueryCache(w4QueryCacheRepository($store));

    // A handler misses and reads the database; a commit evicts the group; the handler then stores what it read.
    expect($cache->lookup('balances', 'k1'))->toBeNull();
    $cache->evictGroup('balances');
    $cache->store('balances', 'k1', 'STALE', 60);

    expect($cache->lookup('balances', 'k1'))->toBeNull();

    // The lookup above started a fresh generation, so the next miss-then-store caches normally again.
    $cache->store('balances', 'k1', 'FRESH', 60);

    expect($cache->lookup('balances', 'k1'))->toBe('FRESH');
})->with('w4 query cache stores');

it('keeps a group evicted across stores until a lookup starts its next generation', function (string $store) {
    $cache = new CacheQueryCache(w4QueryCacheRepository($store));
    $cache->store('balances', 'k1', 1, 60);
    $cache->evictGroup('balances');

    $cache->store('balances', 'k1', 'STALE', 60);
    $cache->store('balances', 'k2', 'STALE', 60);
    $cache->evictKey('balances', 'k1');

    expect($cache->lookup('balances', 'k1'))->toBeNull()
        ->and($cache->lookup('balances', 'k2'))->toBeNull();
})->with('w4 query cache stores');

it('expires an entry after its TTL', function (string $store) {
    Carbon::setTestNow('2026-10-12 10:00:00');
    $cache = new CacheQueryCache(w4QueryCacheRepository($store));
    $cache->store('balances', 'k1', 1, 60);

    Carbon::setTestNow('2026-10-12 10:01:01');

    expect($cache->lookup('balances', 'k1'))->toBeNull();
})->with('w4 query cache stores');

it('never stores null, so null stays a miss', function () {
    $cache = new CacheQueryCache($store = new Repository(new ArrayStore));
    $cache->store('balances', 'k1', null, 60);

    expect($cache->lookup('balances', 'k1'))->toBeNull()
        ->and($store->getStore())->toBeInstanceOf(ArrayStore::class);
});

it('serves the legacy QueryCache contract from the default group', function () {
    $cache = new CacheQueryCache(new Repository(new ArrayStore));
    $cache->put('accounts:alice', 3, null);

    expect($cache->get('accounts:alice'))->toBe(3)
        ->and($cache->lookup('', 'accounts:alice'))->toBe(3);

    $cache->evict('accounts:alice');

    expect($cache->get('accounts:alice'))->toBeNull();
});

it('logs a failing store and answers a miss instead of failing the query', function () {
    $broken = new class implements Store
    {
        public function get($key): mixed
        {
            throw new RuntimeException('cache down');
        }

        /**
         * @param  array<array-key, mixed>  $keys
         * @return array<array-key, mixed>
         */
        public function many(array $keys): array
        {
            throw new RuntimeException('cache down');
        }

        public function put($key, $value, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }

        /** @param  array<array-key, mixed>  $values */
        public function putMany(array $values, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }

        public function increment($key, $value = 1): int|bool
        {
            throw new RuntimeException('cache down');
        }

        public function decrement($key, $value = 1): int|bool
        {
            throw new RuntimeException('cache down');
        }

        public function forever($key, $value): bool
        {
            throw new RuntimeException('cache down');
        }

        public function touch($key, $seconds): bool
        {
            throw new RuntimeException('cache down');
        }

        public function forget($key): bool
        {
            throw new RuntimeException('cache down');
        }

        public function flush(): bool
        {
            return false;
        }

        public function getPrefix(): string
        {
            return '';
        }
    };
    $logger = new RecordingLogger;
    $cache = new CacheQueryCache(new Repository($broken), logger: $logger);

    $cache->store('balances', 'k1', 1, 60);
    $cache->evictGroup('balances');

    expect($cache->lookup('balances', 'k1'))->toBeNull()
        ->and(array_column($logger->records, 'level'))->toBe(['warning', 'warning', 'warning'])
        ->and($logger->records[0]['message'])->toContain('cache down');
});
