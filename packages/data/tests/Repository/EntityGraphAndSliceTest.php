<?php

declare(strict_types=1);

use Firefly\Data\Repository\Page;
use Firefly\Data\Repository\Pageable;
use Firefly\Data\Repository\Slice;
use Firefly\Data\Repository\Sort;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\GraphlessRecordRepository;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordEntry;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable();
    });
    Schema::create('record_entries', function (Blueprint $table): void {
        $table->increments('id');
        $table->integer('record_id');
        $table->string('note');
    });

    foreach ([
        ['status' => 'open', 'amount' => 1, 'email' => 'a@x.test'],
        ['status' => 'open', 'amount' => 2, 'email' => 'b@x.test'],
        ['status' => 'open', 'amount' => 3, 'email' => 'c@x.test'],
        ['status' => 'open', 'amount' => 4, 'email' => 'd@x.test'],
        ['status' => 'closed', 'amount' => 5, 'email' => 'e@x.test'],
    ] as $row) {
        Record::query()->create($row);
    }
    RecordEntry::query()->create(['record_id' => 1, 'note' => 'first']);
    RecordEntry::query()->create(['record_id' => 1, 'note' => 'second']);
    RecordEntry::query()->create(['record_id' => 3, 'note' => 'third']);
});

function graphManifest(): TransactionalManifest
{
    return (new TransactionalScanner)->scan(['Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository']);
}

/** @return list<string> */
function queriesRun(callable $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();

    return array_column(DB::getQueryLog(), 'query');
}

it('eager-loads the attribute paths of a derived method: two queries, relation loaded', function () {
    $repo = new RecordRepository(graphManifest());
    $rows = [];

    $queries = queriesRun(function () use ($repo, &$rows): void {
        $rows = $repo->findByStatusOrderByIdDesc('open');
    });

    expect($rows)->toHaveCount(4)
        ->and($rows[0]->relationLoaded('entries'))->toBeTrue()
        ->and($rows[3]->id)->toBe(1)
        ->and($rows[3]->entries)->toHaveCount(2)
        ->and($queries)->toHaveCount(2)
        ->and($queries[1])->toContain('from "record_entries"');
});

it('applies a NAMED graph to an overridden inherited read, and none without the manifest', function () {
    $withManifest = (new RecordRepository(graphManifest()))->findAll();
    $bare = (new RecordRepository)->findAll();

    expect($withManifest[0]->relationLoaded('entries'))->toBeTrue()
        ->and($bare[0]->relationLoaded('entries'))->toBeFalse();
});

it('refuses a named graph the repository does not declare, at first use', function () {
    expect(fn () => (new GraphlessRecordRepository(graphManifest()))->findAll())
        ->toThrow(ConfigurationException::class, 'Record.nope');
});

it('findSlice over-fetches one row and never counts', function () {
    $repo = new RecordRepository;
    $first = null;
    $last = null;

    $queries = queriesRun(function () use ($repo, &$first, &$last): void {
        $first = $repo->findSlice(Pageable::of(1, 2, Sort::by('id')));
        $last = $repo->findSlice(Pageable::of(3, 2, Sort::by('id')));
    });

    assert($first instanceof Slice && $last instanceof Slice);
    expect($first->items)->toHaveCount(2)
        ->and($first->hasNext())->toBeTrue()
        ->and($first->hasPrevious())->toBeFalse()
        ->and($first->nextPageable()->page)->toBe(2)
        ->and($last->items)->toHaveCount(1)
        ->and($last->hasNext())->toBeFalse()
        ->and($queries)->toHaveCount(2)
        ->and($queries[0])->toContain('limit 3')
        ->and(implode(' ', $queries))->not->toContain('count(');

    $all = $repo->findSlice(Pageable::unpaged());
    expect($all->items)->toHaveCount(5)->and($all->hasNext())->toBeFalse();
});

it('pages a derived query as a Slice when the declared return type says so, with its graph', function () {
    $repo = new RecordRepository(graphManifest());

    $slice = $repo->findByStatusOrderByIdAsc('open', Pageable::of(2, 3));

    expect($slice)->toBeInstanceOf(Slice::class)
        ->and($slice->items)->toHaveCount(1)
        ->and($slice->items[0]->id)->toBe(4)
        ->and($slice->hasNext())->toBeFalse()
        ->and($slice->items[0]->relationLoaded('entries'))->toBeTrue();
});

it('pages an undeclared derived query with a trailing Pageable as a Page, with a total', function () {
    $page = (new RecordRepository)->findByStatus('open', Pageable::of(1, 3, Sort::by('id')->descending()));

    expect($page)->toBeInstanceOf(Page::class)
        ->and($page->total)->toBe(4)
        ->and($page->items)->toHaveCount(3)
        ->and($page->items[0]->id)->toBe(4)
        ->and($page->hasNext())->toBeTrue();
});
