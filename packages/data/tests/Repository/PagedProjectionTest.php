<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\DataSettings;
use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Slice;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\PagedProjection\Order;
use Firefly\Data\Tests\Fixtures\PagedProjection\OrderRepository;
use Firefly\Data\Tests\Fixtures\PagedProjection\OrderSummary;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('orders', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->string('customer');
        $table->integer('amount');
    });
});

/** 25 rows: every one `open`, every one customer `c-1`, amounts 1..25 — a list screen's worth. */
function seedOrders(int $count): void
{
    for ($i = 1; $i <= $count; $i++) {
        Order::query()->create(['status' => 'open', 'customer' => 'c-1', 'amount' => $i]);
    }
}

/**
 * The fixture repository with a REAL scan behind it (the projection rows and the Page/Slice return kinds are
 * the ones TransactionalScanner compiles) and the settings the application's own config produces, so a test
 * that writes `firefly.data.projection.pageable` is read exactly as a booted app reads it.
 */
function pagedProjectionRepository(): OrderRepository
{
    $manifest = (new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\PagedProjection\\' => dirname(__DIR__).'/Fixtures/PagedProjection',
    ]);

    return new OrderRepository($manifest, settings: DataSettings::fromConfig(new Config(config())));
}

it('pages a projection in the database and hydrates the DTO', function () {
    seedOrders(25);
    DB::enableQueryLog();

    $page = pagedProjectionRepository()->findByStatus('open', Pageable::of(1, 10, Sort::by('id')));
    $onOrders = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "orders"')));

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->items)->toHaveCount(10)
        ->and($page->items[0])->toBeInstanceOf(OrderSummary::class)
        ->and($page->items[0]->amount)->toBe(1)
        ->and($page->total)->toBe(25)
        ->and($page->page)->toBe(1)
        ->and($page->totalPages())->toBe(3)
        ->and($page->hasNext())->toBeTrue()
        // the DTO's columns, the count for the total, and a window taken IN THE DATABASE — never 25 rows
        ->and($onOrders)->toBe([
            'select count(*) as "aggregate" from "orders" where "status" = ?',
            'select "id", "customer", "amount" from "orders" where "status" = ? order by "id" asc limit 10 offset 0',
        ]);
});

it('honours the Pageable\'s Sort and its offset on a later page', function () {
    seedOrders(25);

    $page = pagedProjectionRepository()->findByStatus('open', Pageable::of(3, 10, Sort::by('amount')->descending()));

    expect($page->items)->toHaveCount(5)
        ->and($page->items[0]->amount)->toBe(5)
        ->and($page->items[4]->amount)->toBe(1)
        ->and($page->total)->toBe(25)
        ->and($page->hasNext())->toBeFalse();
});

it('slices a projection, fetching size + 1 and reporting hasNext, with no count query', function () {
    seedOrders(25);
    DB::enableQueryLog();

    $slice = pagedProjectionRepository()->findByCustomer('c-1', Pageable::of(1, 10, Sort::by('id')));
    $onOrders = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "orders"')));

    expect($slice)->toBeInstanceOf(Slice::class)
        ->and($slice->items)->toHaveCount(10)
        ->and($slice->items[0])->toBeInstanceOf(OrderSummary::class)
        ->and($slice->hasNext())->toBeTrue()
        ->and($slice->nextPageable()->page)->toBe(2)
        ->and($onOrders)->toBe(['select "id", "customer", "amount" from "orders" where "customer" = ? order by "id" asc limit 11 offset 0'])
        ->and(implode(' ', $onOrders))->not->toContain('count(');
});

it('reports no next page on the last slice', function () {
    seedOrders(25);

    $slice = pagedProjectionRepository()->findByCustomer('c-1', Pageable::of(3, 10, Sort::by('id')));

    expect($slice->items)->toHaveCount(5)->and($slice->hasNext())->toBeFalse();
});

it('still returns the whole unpaged projection when no Pageable is passed', function () {
    seedOrders(25);

    $rows = pagedProjectionRepository()->findByStatusOrderByIdAsc('open');

    expect($rows)->toHaveCount(25)->and($rows[0])->toBeInstanceOf(OrderSummary::class);
});

it('returns the whole unpaged projection when the key is off', function () {
    config()->set('firefly.data.projection.pageable', false);
    seedOrders(25);

    $rows = pagedProjectionRepository()->findByCustomerOrderByAmountAsc('c-1', Pageable::of(1, 10));

    expect($rows)->toHaveCount(25)->and($rows[0])->toBeInstanceOf(OrderSummary::class);
});

it('breaks an un-migrated `array`-typed call site loudly rather than silently, with the key on', function () {
    seedOrders(25);

    // The whole reason firefly.data.projection.pageable exists and defaults to true: the value a projection
    // with a Pageable hands back CHANGES. An application that typed the method `array` because a list is what
    // it used to get gets a TypeError it can see, not a page it silently renders as if it were the whole list.
    expect(fn () => pagedProjectionRepository()->findByCustomerOrderByAmountAsc('c-1', Pageable::of(1, 10)))
        ->toThrow(TypeError::class);
});

it('fetches everything and never has a next page for an unpaged Pageable', function () {
    seedOrders(25);

    $page = pagedProjectionRepository()->findByStatus('open', Pageable::unpaged(Sort::by('id')));
    $slice = pagedProjectionRepository()->findByCustomer('c-1', Pageable::unpaged(Sort::by('id')));

    expect($page->items)->toHaveCount(25)
        ->and($page->total)->toBe(25)
        ->and($slice->items)->toHaveCount(25)
        ->and($slice->hasNext())->toBeFalse();
});

it('still refuses a DTO column the table lacks, before any row is read, on the paged path too', function () {
    seedOrders(2);
    DB::enableQueryLog();

    expect(fn () => pagedProjectionRepository()->findByAmountGreaterThan(0, Pageable::of(1, 10)))
        ->toThrow(ConfigurationException::class, 'nickname')
        ->and(array_column(DB::getQueryLog(), 'query'))->each->not->toContain('from "orders"');
});
