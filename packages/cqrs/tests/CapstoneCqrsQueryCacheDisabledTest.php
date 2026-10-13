<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\Cache\QueryCache;
use Firefly\Cqrs\Cache\QueryCacheInvalidator;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\HandlerCalls;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerBalance;
use Firefly\Cqrs\Tests\Support\CqrsNoCacheCapstoneTestCase;

uses(CqrsNoCacheCapstoneTestCase::class);

it('keeps every query uncached with provider=none, #[CachedQuery] or not', function () {
    /** @var CqrsNoCacheCapstoneTestCase $this */
    $this->queryBus()->ask(new LedgerBalance('alice'));
    $this->queryBus()->ask(new LedgerBalance('alice'));

    expect($this->app()->make(QueryCache::class))->toBeInstanceOf(NoOpQueryCache::class)
        ->and(HandlerCalls::of('balance'))->toBe(2);
});

it('registers no invalidator with provider=none', function () {
    /** @var CqrsNoCacheCapstoneTestCase $this */
    expect($this->app()->bound(QueryCacheInvalidator::class))->toBeFalse();
});
