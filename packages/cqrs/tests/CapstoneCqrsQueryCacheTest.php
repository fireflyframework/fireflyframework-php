<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\CacheQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\AccountStatement;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\HandlerCalls;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerBalance;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\PostEntry;
use Firefly\Cqrs\Tests\Support\CqrsCacheCapstoneTestCase;
use Illuminate\Support\Carbon;

uses(CqrsCacheCapstoneTestCase::class);

it('binds the Laravel-cache adapter for provider=cache', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    expect($this->app()->make(QueryCache::class))->toBeInstanceOf(CacheQueryCache::class);
});

it('answers the second ask of a #[CachedQuery] from the cache', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $this->commandBus()->send(new PostEntry('alice', 10));

    expect($this->queryBus()->ask(new LedgerBalance('alice')))->toBe(10)
        ->and($this->queryBus()->ask(new LedgerBalance('alice')))->toBe(10)
        ->and(HandlerCalls::of('balance'))->toBe(1);
});

it('keys each value object separately', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $this->queryBus()->ask(new LedgerBalance('alice'));
    $this->queryBus()->ask(new LedgerBalance('bob'));
    $this->queryBus()->ask(new LedgerBalance('alice'));

    expect(HandlerCalls::of('balance'))->toBe(2);
});

it('honours the per-query TTL', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    Carbon::setTestNow('2026-10-12 10:00:00');
    $this->queryBus()->ask(new LedgerBalance('alice'));

    Carbon::setTestNow('2026-10-12 10:00:59');
    $this->queryBus()->ask(new LedgerBalance('alice'));
    expect(HandlerCalls::of('balance'))->toBe(1);

    Carbon::setTestNow('2026-10-12 10:01:01');
    $this->queryBus()->ask(new LedgerBalance('alice'));
    expect(HandlerCalls::of('balance'))->toBe(2);
});

it('caches a Cacheable query under its own key', function () {
    /** @var CqrsCacheCapstoneTestCase $this */
    $this->commandBus()->send(new PostEntry('alice', 4));

    expect($this->queryBus()->ask(new AccountStatement('alice')))->toBe([4])
        ->and($this->queryBus()->ask(new AccountStatement('alice')))->toBe([4])
        ->and(HandlerCalls::of('statement'))->toBe(1);
});
