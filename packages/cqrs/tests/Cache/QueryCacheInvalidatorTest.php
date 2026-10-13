<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\CachedQueryPolicy;
use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\InvalidationRule;
use Firefly\Cqrs\Cache\QueryCacheInvalidator;
use Firefly\Cqrs\Cache\QueryCacheKey;
use Firefly\Cqrs\Cache\QueryCachePolicies;
use Firefly\Cqrs\Tests\Cache\Fixtures\LimitedBalance;
use Firefly\Cqrs\Tests\CacheScannerFixtures\EntryPosted;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerBalance;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerTotals;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerTier;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\TierBalance;
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
        TierBalance::class => new CachedQueryPolicy(TierBalance::class, null, 'tiers'),
        LimitedBalance::class => new CachedQueryPolicy(LimitedBalance::class, null, 'limits'),
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

function w4TierRule(): InvalidationRule
{
    return new InvalidationRule('', 'TierChanged', TierBalance::class, null, ['account' => 'account', 'tier' => 'tier'], [
        'account' => 'string',
        'tier' => LedgerTier::class,
    ]);
}

it('evicts the group, warning once, when an envelope carries an enum-typed query property as its backing value', function () {
    [$invalidator, $cache, $logger] = w4Invalidator([w4TierRule()]);
    $gold = (string) QueryCacheKey::of(new TierBalance('a', LedgerTier::Gold));
    $silver = (string) QueryCacheKey::of(new TierBalance('b', LedgerTier::Silver));
    $cache->store('tiers', $gold, 1, null);
    $cache->store('tiers', $silver, 2, null);

    $invalidator->onEnvelope(new EventEnvelope('TierChanged', 'ledger.events', ['account' => 'a', 'tier' => 'gold']));
    $cache->store('tiers', $gold, 1, null);
    $invalidator->onEnvelope(new EventEnvelope('TierChanged', 'ledger.events', ['account' => 'a', 'tier' => 'gold']));

    expect($cache->lookup('tiers', $gold))->toBeNull()
        ->and($cache->lookup('tiers', $silver))->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('carries `tier` as string')->toContain('evicting the whole group');
});

it('evicts the one enum-typed entry when the envelope payload still carries the case, as on an in-memory bus', function () {
    [$invalidator, $cache, $logger] = w4Invalidator([w4TierRule()]);
    $gold = (string) QueryCacheKey::of(new TierBalance('a', LedgerTier::Gold));
    $silver = (string) QueryCacheKey::of(new TierBalance('b', LedgerTier::Silver));
    $cache->store('tiers', $gold, 1, null);
    $cache->store('tiers', $silver, 2, null);

    $invalidator->onEnvelope(new EventEnvelope('TierChanged', 'ledger.events', ['account' => 'a', 'tier' => LedgerTier::Gold]));

    expect($cache->lookup('tiers', $gold))->toBeNull()
        ->and($cache->lookup('tiers', $silver))->toBe(2)
        ->and($logger->records)->toBe([]);
});

it('rebuilds a scalar key from an envelope, reading a JSON integer as the float the query declares and null as a nullable int', function () {
    [$invalidator, $cache, $logger] = w4Invalidator([new InvalidationRule('', 'LimitChanged', LimitedBalance::class, null,
        ['account' => 'account', 'limit' => 'limit', 'page' => 'page'],
        ['account' => 'string', 'limit' => 'float', 'page' => '?int'],
    )]);
    $target = (string) QueryCacheKey::of(new LimitedBalance('a', 5.0, null));
    $other = (string) QueryCacheKey::of(new LimitedBalance('b', 5.0, null));
    $cache->store('limits', $target, 1, null);
    $cache->store('limits', $other, 2, null);

    $invalidator->onEnvelope(new EventEnvelope('LimitChanged', 'd', ['account' => 'a', 'limit' => 5, 'page' => null]));

    expect($cache->lookup('limits', $target))->toBeNull()
        ->and($cache->lookup('limits', $other))->toBe(2)
        ->and($logger->records)->toBe([]);
});

it('evicts the group for an envelope when a scalar payload value has the wrong type or the rule has no recorded types', function () {
    [$invalidator, $cache] = w4Invalidator([
        new InvalidationRule('', 'LimitChanged', LimitedBalance::class, null,
            ['account' => 'account', 'limit' => 'limit', 'page' => 'page'],
            ['account' => 'string', 'limit' => 'float', 'page' => '?int'],
        ),
        new InvalidationRule(EntryPosted::class, 'EntryPosted', LedgerBalance::class, null, ['account' => 'account']),
    ]);
    $cache->store('limits', (string) QueryCacheKey::of(new LimitedBalance('b', 5.0, 2)), 2, null);
    $cache->store('balances', w4BalanceKey('b'), 2, null);

    $invalidator->onEnvelope(new EventEnvelope('LimitChanged', 'd', ['account' => 'a', 'limit' => 5, 'page' => '2']));
    $invalidator->onEnvelope(new EventEnvelope('EntryPosted', 'd', ['account' => 'a']));

    expect($cache->lookup('limits', (string) QueryCacheKey::of(new LimitedBalance('b', 5.0, 2))))->toBeNull()
        ->and($cache->lookup('balances', w4BalanceKey('b')))->toBeNull();
});
