<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\QueryCacheKey;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerBalance;
use Firefly\Cqrs\Tests\CacheScannerFixtures\LedgerTotals;

enum W4KeySide: string
{
    case Debit = 'debit';
}

it('derives the same key for two equal value objects and different keys for different ones', function () {
    expect(QueryCacheKey::of(new LedgerBalance('a')))->toBe(QueryCacheKey::of(new LedgerBalance('a')))
        ->and(QueryCacheKey::of(new LedgerBalance('a')))->not->toBe(QueryCacheKey::of(new LedgerBalance('b')))
        ->and(QueryCacheKey::of(new LedgerBalance('a')))->toMatch('/^[0-9a-f]{64}$/');
});

it('keys by the query class as well as its properties', function () {
    $other = new class('a')
    {
        public function __construct(public string $account) {}
    };

    expect(QueryCacheKey::of(new LedgerBalance('a')))->not->toBe(QueryCacheKey::of($other))
        ->and(QueryCacheKey::of(new LedgerTotals))->toBe(QueryCacheKey::fromProperties(LedgerTotals::class, []));
});

it('builds the key a value object would get from a property map, independent of key order', function () {
    $query = new class('a', 3)
    {
        public function __construct(public string $account, public int $page) {}
    };

    expect(QueryCacheKey::fromProperties($query::class, ['page' => 3, 'account' => 'a']))->toBe(QueryCacheKey::of($query))
        ->and(QueryCacheKey::fromProperties($query::class, ['page' => '3', 'account' => 'a']))->not->toBe(QueryCacheKey::of($query));
});

it('normalises enums, dates, nested objects and lists', function () {
    $at = new DateTimeImmutable('2026-10-12T10:00:00+02:00');
    $a = new class(W4KeySide::Debit, $at, ['x', 'y'], new LedgerBalance('a'))
    {
        /** @param list<string> $tags */
        public function __construct(public W4KeySide $side, public DateTimeImmutable $at, public array $tags, public LedgerBalance $nested) {}
    };
    $b = new ($a::class)(W4KeySide::Debit, new DateTimeImmutable('2026-10-12T10:00:00+02:00'), ['x', 'y'], new LedgerBalance('a'));
    $c = new ($a::class)(W4KeySide::Debit, $at, ['y', 'x'], new LedgerBalance('a'));

    expect(QueryCacheKey::of($a))->not->toBeNull()
        ->and(QueryCacheKey::of($a))->toBe(QueryCacheKey::of($b))
        ->and(QueryCacheKey::of($a))->not->toBe(QueryCacheKey::of($c));
});

it('refuses to key what cannot be part of a stable key', function () {
    $closure = new class(static fn (): int => 1)
    {
        public function __construct(public Closure $callback) {}
    };
    $nan = new class(NAN)
    {
        public function __construct(public float $value) {}
    };

    expect(QueryCacheKey::of($closure))->toBeNull()
        ->and(QueryCacheKey::of($nan))->toBeNull();
});

it('refuses to key a query whose state is private or protected, instead of colliding on its public view', function () {
    $query = new class('a')
    {
        public function __construct(private string $account) {}

        public function account(): string
        {
            return $this->account;
        }
    };
    $protected = new class('a')
    {
        public function __construct(protected string $account) {}
    };

    expect(QueryCacheKey::of($query))->toBeNull()
        ->and(QueryCacheKey::of(new ($query::class)('b')))->toBeNull()
        ->and(QueryCacheKey::of($protected))->toBeNull();
});

it('refuses to key a public query that nests a value object with private state', function () {
    $money = new class(100)
    {
        public function __construct(private int $cents) {}

        public function cents(): int
        {
            return $this->cents;
        }
    };
    $query = new class($money)
    {
        public function __construct(public object $amount) {}
    };

    expect(QueryCacheKey::of($query))->toBeNull()
        ->and(QueryCacheKey::of(new ($query::class)(new ($money::class)(200))))->toBeNull();
});
