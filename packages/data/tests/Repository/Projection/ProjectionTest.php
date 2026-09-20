<?php

declare(strict_types=1);

use Firefly\Data\Repository\EloquentRepository;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Fixtures\Repository\RecordSummary;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

/**
 * A repository whose projection rows are hand-built (no attributes to scan): the `findFirst…` and `count…` shapes
 * the shared fixture does not declare. Declared here, with no attribute, so the fixture's compiled map — pinned
 * by the scanner tests — stays exactly as it is.
 *
 * @extends EloquentRepository<Record>
 *
 * @method RecordSummary|null findFirstByStatusOrderByAmountDesc(string $status)
 * @method int countByStatus(string $status)
 */
final class ProjectingFirstRepository extends EloquentRepository
{
    protected string $model = Record::class;
}

beforeEach(function (): void {
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable();
    });

    foreach ([
        ['status' => 'open', 'amount' => 150, 'email' => 'b@x.test'],
        ['status' => 'open', 'amount' => 50, 'email' => null],
        ['status' => 'closed', 'amount' => 250, 'email' => 'c@x.test'],
    ] as $row) {
        Record::query()->create($row);
    }
});

function projectingRepository(): RecordRepository
{
    return new RecordRepository((new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__, 2).'/Fixtures/Repository',
    ]));
}

/** The projection row the scanner compiles for RecordSummary, attached by hand to the two undeclared methods. */
function projectingFirstRepository(): ProjectingFirstRepository
{
    $summary = (new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__, 2).'/Fixtures/Repository',
    ])->repositoryMethod(RecordRepository::class, 'findByStatusOrderByAmountAsc')['projection'] ?? null;
    assert($summary !== null);

    $row = ['modifying' => null, 'projection' => $summary, 'lock' => null, 'entityGraph' => null, 'returns' => null];

    return new ProjectingFirstRepository(new TransactionalManifest([], [], [
        ProjectingFirstRepository::class => ['findFirstByStatusOrderByAmountDesc' => $row, 'countByStatus' => $row],
    ]));
}

it('hydrates a derived query into the DTO and selects only the DTO\'s columns', function () {
    DB::enableQueryLog();
    $rows = projectingRepository()->findByStatusOrderByAmountAsc('open');
    // the first projection in a process also reads the table's column listing (pragma_table_xinfo on sqlite);
    // what touches the table itself must be the one projected select
    $onRecords = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql): bool => str_contains($sql, 'from "records"')));

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toBeInstanceOf(RecordSummary::class)
        ->and($rows[0]->amount)->toBe(50)
        ->and($rows[0]->email)->toBeNull()
        ->and($rows[1]->email)->toBe('b@x.test')
        ->and($onRecords)->toBe(['select "id", "email", "amount" from "records" where "status" = ? order by "amount" asc']);
});

it('hydrates a #[Query] method\'s rows into the same DTO', function () {
    $rows = projectingRepository()->summariesByStatusRaw('closed');

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toBeInstanceOf(RecordSummary::class)
        ->and($rows[0]->id)->toBeInt()
        ->and($rows[0]->amount)->toBe(250);
});

it('returns one DTO or null for a findFirst projection, and ignores the projection on a count', function () {
    $repo = projectingFirstRepository();

    $first = $repo->findFirstByStatusOrderByAmountDesc('open');

    expect($first)->toBeInstanceOf(RecordSummary::class)
        ->and($first?->amount)->toBe(150)
        ->and($repo->findFirstByStatusOrderByAmountDesc('archived'))->toBeNull()
        ->and($repo->countByStatus('open'))->toBe(2);
});

it('fails loud at first use when the DTO wants a column the table does not have', function () {
    expect(fn () => projectingRepository()->findByAmountLessThan(1000))
        ->toThrow(ConfigurationException::class, 'nickname');
});

it('names the table and its columns in that failure, before any row is read, even on sqlite', function () {
    // sqlite would NOT reject `select "nickname"`: it reads the unknown double-quoted identifier as a string
    // literal, so without the first-use check the misconfiguration would surface as rows of the word "nickname".
    DB::enableQueryLog();

    expect(fn () => projectingRepository()->findByAmountLessThan(1000))->toThrow(
        ConfigurationException::class,
        'Projection [Firefly\\Data\\Tests\\Fixtures\\Repository\\RecordNickname] on Record selects column [nickname], '
        .'which table [records] does not have (it has [id, status, amount, email]).',
    )->and(array_column(DB::getQueryLog(), 'query'))->each->not->toContain('from "records"');
});
