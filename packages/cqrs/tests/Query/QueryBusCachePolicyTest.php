<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\CachedQueryPolicy;
use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Cache\QueryCacheKey;
use Firefly\Cqrs\Cache\QueryCachePolicies;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Query\DefaultQueryBus;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerBalance;
use Firefly\Cqrs\Validation\MessageValidator;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

function w4PolicyBus(HandlerRegistry $registry, QueryCache $cache, ?QueryCachePolicies $policies, ?int $defaultTtl = null): DefaultQueryBus
{
    return new DefaultQueryBus($registry, new MessageValidator(null), new AllowAllAuthorizer, new CorrelationContext, new NoOpCqrsMetrics, $cache, $defaultTtl, null, $policies);
}

it('caches a query that has a policy under a key derived from its value object, in its group', function () {
    $registry = new HandlerRegistry;
    $calls = 0;
    $registry->registerQueryHandler(LedgerBalance::class, function (object $query) use (&$calls): int {
        $calls++;

        return $query instanceof LedgerBalance ? strlen($query->account) : 0;
    });
    $cache = new CacheQueryCache(new Repository(new ArrayStore));
    $bus = w4PolicyBus($registry, $cache, new QueryCachePolicies([LedgerBalance::class => new CachedQueryPolicy(LedgerBalance::class, 60, 'balances')]));

    expect($bus->ask(new LedgerBalance('alice')))->toBe(5)
        ->and($bus->ask(new LedgerBalance('alice')))->toBe(5)
        ->and($calls)->toBe(1)
        ->and($cache->lookup('balances', (string) QueryCacheKey::of(new LedgerBalance('alice'))))->toBe(5);

    $cache->evictGroup('balances');

    expect($bus->ask(new LedgerBalance('alice')))->toBe(5)->and($calls)->toBe(2);
});

it('does not cache a plain query without a policy, exactly as before', function () {
    $registry = new HandlerRegistry;
    $calls = 0;
    $registry->registerQueryHandler(LedgerBalance::class, function () use (&$calls): int {
        return ++$calls;
    });
    $bus = w4PolicyBus($registry, new CacheQueryCache(new Repository(new ArrayStore)), new QueryCachePolicies);

    expect($bus->ask(new LedgerBalance('a')))->toBe(1)->and($bus->ask(new LedgerBalance('a')))->toBe(2);
});

it('keeps calling get()/put() with the flat key on a QueryCache that has no groups', function () {
    $registry = new HandlerRegistry;
    $registry->registerQueryHandler(LedgerBalance::class, static fn (): int => 7);
    $legacy = new class implements QueryCache
    {
        /** @var list<array{key: string, ttl: int|null}> */
        public array $puts = [];

        public function get(string $key): mixed
        {
            return null;
        }

        public function put(string $key, mixed $value, ?int $ttl): void
        {
            $this->puts[] = ['key' => $key, 'ttl' => $ttl];
        }

        public function evict(string $key): void {}
    };
    $bus = w4PolicyBus($registry, $legacy, new QueryCachePolicies([LedgerBalance::class => new CachedQueryPolicy(LedgerBalance::class, null, LedgerBalance::class)]), 30);

    $bus->ask(new LedgerBalance('a'));

    expect($legacy->puts)->toBe([['key' => (string) QueryCacheKey::of(new LedgerBalance('a')), 'ttl' => 30]]);
});
