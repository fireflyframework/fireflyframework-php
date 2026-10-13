<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\QueryCacheInvalidator;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\AccountStatement;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\HandlerCalls;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerBalance;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerTier;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerTotals;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\PostEntry;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\RejectEntry;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\TierBalance;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\TierChanged;
use Firefly\Cqrs\Tests\Support\CqrsCacheCapstoneTestCase;

uses(CqrsCacheCapstoneTestCase::class);

it('evicts only the posted account after the command commits', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new LedgerBalance('alice'));
    $queries->ask(new LedgerBalance('bob'));

    $this->commandBus()->send(new PostEntry('alice', 7));

    expect($queries->ask(new LedgerBalance('alice')))->toBe(7)
        ->and($queries->ask(new LedgerBalance('bob')))->toBe(0)
        ->and(HandlerCalls::of('balance'))->toBe(3);
});

it('evicts a whole group and a Cacheable key template from the same event', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new LedgerTotals);
    $queries->ask(new AccountStatement('alice'));

    $this->commandBus()->send(new PostEntry('alice', 3));

    expect($queries->ask(new LedgerTotals))->toBe(3)
        ->and($queries->ask(new AccountStatement('alice')))->toBe([3])
        ->and(HandlerCalls::of('totals'))->toBe(2)
        ->and(HandlerCalls::of('statement'))->toBe(2);
});

it('evicts nothing when the transaction rolls back', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new LedgerBalance('alice'));

    expect(fn () => $this->commandBus()->send(new RejectEntry('alice', 9)))->toThrow(RuntimeException::class);

    expect($queries->ask(new LedgerBalance('alice')))->toBe(0)
        ->and(HandlerCalls::of('balance'))->toBe(1);
});

it('evicts on an EDA envelope another process published', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new LedgerBalance('alice'));

    $this->events->publish('ledger.events', 'EntryPosted', ['account' => 'alice', 'amount' => 1]);
    $queries->ask(new LedgerBalance('alice'));

    expect(HandlerCalls::of('balance'))->toBe(2);
});

it('applies the firefly.cqrs.query.cache.invalidation rules to events with no local class', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new LedgerTotals);

    $this->events->publish('ledger.events', 'ledger.closed', ['period' => '2026-09']);
    $queries->ask(new LedgerTotals);

    expect(HandlerCalls::of('totals'))->toBe(2)
        ->and($this->app()->make(QueryCacheInvalidator::class)->eventTypes())->toContain('EntryPosted', 'ledger.closed');
});

it('evicts exactly the one entry a scalar with map names when another process publishes the envelope', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new LedgerBalance('alice'));
    $queries->ask(new LedgerBalance('bob'));

    $this->events->publish('ledger.events', 'EntryPosted', ['account' => 'alice', 'amount' => 1]);
    $queries->ask(new LedgerBalance('alice'));
    $queries->ask(new LedgerBalance('bob'));

    expect(HandlerCalls::of('balance'))->toBe(3);
});

// The envelope carries the enum as 'gold' (what JSON makes of LedgerTier::Gold), which cannot rebuild the typed
// digest the entry lives under: the group goes instead, so the stale alice entry stops serving.
it('evicts the group when an envelope payload cannot rebuild an enum-typed with-map key', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new TierBalance('alice', LedgerTier::Gold));
    $queries->ask(new TierBalance('bob', LedgerTier::Silver));

    $this->events->publish('ledger.events', 'TierChanged', ['account' => 'alice', 'tier' => 'gold']);
    $queries->ask(new TierBalance('alice', LedgerTier::Gold));
    $queries->ask(new TierBalance('bob', LedgerTier::Silver));

    expect(HandlerCalls::of('tier'))->toBe(4);
});

it('evicts the one enum-typed entry in process, where the event carries the real case', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $queries = $this->queryBus();
    $queries->ask(new TierBalance('alice', LedgerTier::Gold));
    $queries->ask(new TierBalance('bob', LedgerTier::Silver));

    $this->app()->make('events')->dispatch(new TierChanged('alice', LedgerTier::Gold));
    $queries->ask(new TierBalance('alice', LedgerTier::Gold));
    $queries->ask(new TierBalance('bob', LedgerTier::Silver));

    expect(HandlerCalls::of('tier'))->toBe(3);
});
