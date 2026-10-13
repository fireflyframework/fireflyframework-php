<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\CachedQueryPolicy;
use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\InvalidationRule;
use Firefly\Cqrs\Cache\QueryCacheInvalidator;
use Firefly\Cqrs\Cache\QueryCacheKey;
use Firefly\Cqrs\Cache\QueryCachePolicies;
use Firefly\Cqrs\Tests\CacheScannerFixtures\EntryPosted;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerBalance;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerTotals;
use Firefly\Cqrs\Tests\Support\RecordingLogger;
use Firefly\Eda\EventEnvelope;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/**
 * @param  list<InvalidationRule>  $rules
 * @return array{0: QueryCacheInvalidator, 1: CacheQueryCache, 2: RecordingLogger}
 */
function w4Invalidator(array $rules): array
{
    $cache = new CacheQueryCache(new Repository(new ArrayStore));
    $policies = new QueryCachePolicies([
        LedgerBalance::class => new CachedQueryPolicy(LedgerBalance::class, null, 'balances'),
        LedgerTotals::class => new CachedQueryPolicy(LedgerTotals::class, null, 'totals'),
    ]);
    $logger = new RecordingLogger;

    return [new QueryCacheInvalidator($cache, $policies, $rules, $logger), $cache, $logger];
}

function w4BalanceKey(string $account): string
{
    return (string) QueryCacheKey::of(new LedgerBalance($account));
}

it('evicts the one entry a with-map rule names, from an in-process event', function () {
    [$invalidator, $cache] = w4Invalidator([new InvalidationRule(EntryPosted::class, 'EntryPosted', LedgerBalance::class, null, ['account' => 'account'])]);
    $cache->store('balances', w4BalanceKey('a'), 1, null);
    $cache->store('balances', w4BalanceKey('b'), 2, null);

    expect($invalidator->onEvent(new EntryPosted('a', 5)))->toBe(1)
        ->and($cache->lookup('balances', w4BalanceKey('a')))->toBeNull()
        ->and($cache->lookup('balances', w4BalanceKey('b')))->toBe(2);
});

it('evicts a whole group for a rule with neither key nor with', function () {
    [$invalidator, $cache] = w4Invalidator([new InvalidationRule(EntryPosted::class, 'EntryPosted', LedgerTotals::class)]);
    $cache->store('totals', 'x', 1, null);

    $invalidator->onEvent(new EntryPosted('a', 5));

    expect($cache->lookup('totals', 'x'))->toBeNull();
});

it('resolves a key template from the event, or from a dotted path into an envelope payload', function () {
    [$invalidator, $cache] = w4Invalidator([
        new InvalidationRule(EntryPosted::class, 'EntryPosted', LedgerBalance::class, 'statement:{account}'),
        new InvalidationRule('', 'ledger.adjusted', LedgerBalance::class, 'statement:{entry.account}'),
    ]);
    $cache->store('balances', 'statement:a', 1, null);
    $cache->store('balances', 'statement:b', 2, null);

    $invalidator->onEvent(new EntryPosted('a', 5));
    $invalidator->onEnvelope(new EventEnvelope('ledger.adjusted', 'ledger.events', ['entry' => ['account' => 'b']]));

    expect($cache->lookup('balances', 'statement:a'))->toBeNull()
        ->and($cache->lookup('balances', 'statement:b'))->toBeNull();
});

it('falls back to evicting the group when the event cannot fill a placeholder', function () {
    [$invalidator, $cache, $logger] = w4Invalidator([new InvalidationRule('', 'ledger.adjusted', LedgerBalance::class, null, ['account' => 'account'])]);
    $cache->store('balances', w4BalanceKey('a'), 1, null);

    $invalidator->onEnvelope(new EventEnvelope('ledger.adjusted', 'ledger.events', ['other' => 'x']));

    expect($cache->lookup('balances', w4BalanceKey('a')))->toBeNull()
        ->and($logger->records[0]['message'])->toContain('evicting the whole group');
});

it('matches envelopes by exact event type and events by class, and ignores everything else', function () {
    [$invalidator, $cache] = w4Invalidator([new InvalidationRule(EntryPosted::class, 'EntryPosted', LedgerTotals::class)]);
    $cache->store('totals', 'x', 1, null);

    expect($invalidator->onEnvelope(new EventEnvelope('EntryPostedLater', 'd')))->toBe(0)
        ->and($invalidator->onEvent(new stdClass))->toBe(0)
        ->and($cache->lookup('totals', 'x'))->toBe(1)
        ->and($invalidator->onEnvelope(new EventEnvelope('EntryPosted', 'd')))->toBe(1)
        ->and($invalidator->eventTypes())->toBe(['EntryPosted'])
        ->and($invalidator->hasClassRules())->toBeTrue();
});
