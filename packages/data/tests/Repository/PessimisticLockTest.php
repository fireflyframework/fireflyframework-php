<?php

declare(strict_types=1);

use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\MySqlConnection;
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

    Record::query()->create(['status' => 'open', 'amount' => 50, 'email' => 'a@x.test']);
});

function lockingRepository(): RecordRepository
{
    return new RecordRepository((new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository',
    ]));
}

it('refuses a locked read outside a transaction, as Spring does', function () {
    $repo = lockingRepository();

    expect(fn () => $repo->findByEmail('a@x.test'))->toThrow(TransactionRequiredException::class)
        ->and(fn () => $repo->findByAmountBetween(1, 100))->toThrow(TransactionRequiredException::class)
        ->and(fn () => $repo->findByIdForUpdate(1))->toThrow(TransactionRequiredException::class);
});

it('reads under the lock inside a transaction (sqlite has no row locks, so the read simply succeeds)', function () {
    $repo = lockingRepository();

    DB::transaction(function () use ($repo): void {
        expect($repo->findByEmail('a@x.test'))->toHaveCount(1)
            ->and($repo->findByAmountBetween(1, 100))->toHaveCount(1)
            ->and($repo->findByIdForUpdate(1)?->email)->toBe('a@x.test')
            ->and($repo->findByIdForUpdate(999))->toBeNull();
    });
});

it('emits FOR UPDATE and LOCK IN SHARE MODE on a MySQL-grammar connection', function () {
    // A MySqlConnection over an in-memory sqlite PDO, under pretend(): nothing executes, the grammar compiles,
    // and every statement is exactly the SQL a real MySQL server would receive. The SQL is read from the
    // QueryExecuted event rather than pretend()'s returned log, which interpolates the bindings into the
    // statement it records; the event carries the statement as compiled, placeholders and all.
    config()->set('database.connections.mysqlish', ['driver' => 'mysql', 'database' => 'pretend']);
    DB::extend('mysqlish', static fn (array $config, string $name): MySqlConnection => new MySqlConnection(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => 'mysql', 'name' => $name]));
    config()->set('database.default', 'mysqlish');

    $repo = lockingRepository();
    $connection = DB::connection('mysqlish');

    $queries = [];
    $connection->listen(static function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $connection->pretend(function () use ($repo, $connection): void {
        $connection->beginTransaction();
        try {
            $repo->findByEmail('a@x.test');
            $repo->findByAmountBetween(1, 2);
            $repo->findByIdForUpdate(1);
        } finally {
            $connection->rollBack();
        }
    });

    expect($queries)->toBe([
        'select * from `records` where `email` = ? for update',
        'select * from `records` where `amount` between ? and ? lock in share mode',
        'select * from `records` where `records`.`id` = ? limit 1 for update',
    ]);
});
