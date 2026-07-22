<?php

declare(strict_types=1);

use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Specification\Specifications;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable();
    });

    foreach ([
        ['status' => 'open', 'amount' => 50, 'email' => 'a@x.test'],
        ['status' => 'open', 'amount' => 150, 'email' => 'b@x.test'],
        ['status' => 'open', 'amount' => 250, 'email' => 'c@x.test'],
        ['status' => 'closed', 'amount' => 999, 'email' => 'd@x.test'],
    ] as $row) {
        Record::query()->create($row);
    }
});

$amountAbove100 = fn () => Specifications::where(fn (Builder $q) => $q->where('amount', '>', 100));
$statusOpen = fn () => Specifications::where(fn (Builder $q) => $q->where('status', 'open'));
$statusClosed = fn () => Specifications::where(fn (Builder $q) => $q->where('status', 'closed'));
$amountBelow100 = fn () => Specifications::where(fn (Builder $q) => $q->where('amount', '<', 100));

it('composes AND: only the intersection matches', function () use ($amountAbove100, $statusOpen) {
    $rows = (new RecordRepository)->findBySpecification(Specifications::allOf($amountAbove100(), $statusOpen()));

    expect(array_map(static fn (Record $r): int => $r->amount, $rows))->toBe([150, 250]);
});

it('composes OR: the union matches (rows AND would exclude)', function () use ($statusClosed, $amountBelow100) {
    $rows = (new RecordRepository)->findBySpecification(Specifications::anyOf($statusClosed(), $amountBelow100()));

    // closed(999) OR amount<100(50) -> two rows; an AND here would yield zero.
    expect(array_map(static fn (Record $r): int => $r->amount, $rows))->toEqualCanonicalizing([50, 999]);
});

it('negates a specification', function () use ($statusOpen) {
    $rows = (new RecordRepository)->findBySpecification(Specifications::not($statusOpen()));

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->status)->toBe('closed');
});

it('pages a specification with correct totals', function () use ($amountAbove100) {
    $page = (new RecordRepository)->findBySpecificationPaged($amountAbove100(), Pageable::of(1, 2));

    expect($page->total)->toBe(3)          // 150, 250, 999
        ->and($page->items)->toHaveCount(2)
        ->and($page->totalPages())->toBe(2)
        ->and($page->hasNext())->toBeTrue();
});

it('folds zero specifications to the match-all identity', function () {
    // Specifications::allOf() with no arguments is the empty AND — no constraints, every row. This exercises the
    // matchAll() identity path; a matchAll() that constrained anything (WHERE false / a stray column) drops the count.
    $rows = (new RecordRepository)->findBySpecification(Specifications::allOf());

    expect($rows)->toHaveCount(4);
});
