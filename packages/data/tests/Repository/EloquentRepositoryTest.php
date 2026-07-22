<?php

declare(strict_types=1);

use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
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
});

/**
 * @return list<int> the generated ids, in insertion order
 */
function seedRecords(): array
{
    $ids = [];
    foreach ([
        ['status' => 'open', 'amount' => 50, 'email' => 'a@x.test'],
        ['status' => 'open', 'amount' => 150, 'email' => 'b@x.test'],
        ['status' => 'closed', 'amount' => 250, 'email' => 'c@x.test'],
    ] as $row) {
        $ids[] = (int) Record::query()->create($row)->id;
    }

    return $ids;
}

it('saves a model and finds it by id', function () {
    $repo = new RecordRepository;
    $saved = $repo->save(new Record(['status' => 'open', 'amount' => 10, 'email' => 'z@x.test']));

    expect($saved->id)->not->toBeNull();

    $found = $repo->findById($saved->id);
    expect($found)->not->toBeNull()
        ->and($found?->status)->toBe('open')
        ->and($found?->amount)->toBe(10);
});

it('saves many and reads them all back', function () {
    $repo = new RecordRepository;
    $repo->saveAll([
        new Record(['status' => 'open', 'amount' => 1, 'email' => 'x@x.test']),
        new Record(['status' => 'open', 'amount' => 2, 'email' => 'y@x.test']),
    ]);

    expect($repo->findAll())->toHaveCount(2)
        ->and($repo->count())->toBe(2);
});

it('finds many by id and reports existence', function () {
    [$a, $b, $c] = seedRecords();
    $repo = new RecordRepository;

    expect($repo->findAllById([$a, $c]))->toHaveCount(2)
        ->and($repo->existsById($b))->toBeTrue()
        ->and($repo->existsById(9999))->toBeFalse();
});

it('deletes by instance, by id and wholesale', function () {
    [$a, $b, $c] = seedRecords();
    $repo = new RecordRepository;

    $repo->deleteById($a);
    expect($repo->count())->toBe(2);

    $found = $repo->findById($b);
    expect($found)->not->toBeNull();
    $repo->delete($found ?? throw new RuntimeException('Record not found.'));
    expect($repo->count())->toBe(1);

    $repo->deleteAll();
    expect($repo->count())->toBe(0);
});

it('paginates with correct totals, pages and slice', function () {
    seedRecords();
    $repo = new RecordRepository;

    $page1 = $repo->findPaged(Pageable::of(1, 2, Sort::by('id')));

    expect($page1->total)->toBe(3)
        ->and($page1->items)->toHaveCount(2)
        ->and($page1->totalPages())->toBe(2)
        ->and($page1->hasNext())->toBeTrue()
        ->and($page1->hasPrevious())->toBeFalse()
        ->and($page1->items[0]->amount)->toBe(50);

    $page2 = $repo->findPaged(Pageable::of(2, 2, Sort::by('id')));

    expect($page2->items)->toHaveCount(1)
        ->and($page2->hasNext())->toBeFalse()
        ->and($page2->hasPrevious())->toBeTrue()
        ->and($page2->items[0]->amount)->toBe(250);
});

it('sorts without paging', function () {
    seedRecords();
    $repo = new RecordRepository;

    $desc = $repo->findSorted(Sort::by('amount')->descending());

    expect(array_map(static fn (Record $r): int => $r->amount, $desc))->toBe([250, 150, 50]);
});
