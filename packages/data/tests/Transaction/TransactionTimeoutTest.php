<?php

declare(strict_types=1);

use Firefly\Data\DataSettings;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Kernel\Exception\Infrastructure\TransactionTimedOutException;
use Illuminate\Database\PostgresConnection;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

/** A genuinely slow sqlite statement (a few hundred thousand rows through a recursive CTE), then the rest of the second. */
function overrunOneSecond(): void
{
    DB::table('widgets')->insert(['name' => 'slow']);
    DB::select('with recursive c(x) as (select 1 union all select x + 1 from c where x < 500000) select count(*) as n from c');
    usleep(1_100_000);
}

it('rolls back and throws TransactionTimedOutException when the work overruns its timeout', function () {
    $template = new TransactionTemplate;

    try {
        $template->execute(overrunOneSecond(...), new TransactionalDescriptor(timeout: 1));
        $this->fail('expected the transaction to time out');
    } catch (TransactionTimedOutException $e) {
        expect($e->httpStatus())->toBe(504)
            ->and($e->errorCode())->toBe('TRANSACTION_TIMED_OUT');
    }

    expect(DB::table('widgets')->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('commits work that finishes inside its timeout', function () {
    (new TransactionTemplate)->execute(function (): void {
        DB::table('widgets')->insert(['name' => 'quick']);
    }, new TransactionalDescriptor(timeout: 5));

    expect(DB::table('widgets')->count())->toBe(1);
});

it('applies firefly.data.transaction.default-timeout when the attribute names none, and the attribute wins when it does', function () {
    $template = new TransactionTemplate(null, null, new DataSettings(defaultTimeout: 1));

    expect(fn () => $template->execute(overrunOneSecond(...)))->toThrow(TransactionTimedOutException::class)
        ->and(DB::table('widgets')->count())->toBe(0);

    $template->execute(overrunOneSecond(...), new TransactionalDescriptor(timeout: 10));

    expect(DB::table('widgets')->count())->toBe(1);
});

it('never times out a joined transaction on its own: the outermost deadline is the one that counts', function () {
    $template = new TransactionTemplate;

    $template->execute(function () use ($template): void {
        // inner REQUIRED joins; its timeout: 1 is not started separately, so the overrun inside it is not judged
        $template->execute(overrunOneSecond(...), new TransactionalDescriptor(timeout: 1));
    }, new TransactionalDescriptor(timeout: 10));

    expect(DB::table('widgets')->count())->toBe(1);
});

it('issues the driver statement timeout at transaction start, unless statement-timeout is off', function () {
    config()->set('database.connections.pgsqlish', ['driver' => 'pgsql', 'database' => 'pretend']);
    DB::extend('pgsqlish', static fn (array $config, string $name): PostgresConnection => new PostgresConnection(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => 'pgsql', 'name' => $name]));
    $connection = DB::connection('pgsqlish');

    $on = $connection->pretend(static function (): void {
        (new TransactionTemplate(null, null, new DataSettings(defaultTimeout: 3)))->execute(static fn (): int => 1, new TransactionalDescriptor(connection: 'pgsqlish'));
    });
    $off = $connection->pretend(static function (): void {
        (new TransactionTemplate(null, null, new DataSettings(defaultTimeout: 3, statementTimeout: false)))->execute(static fn (): int => 1, new TransactionalDescriptor(connection: 'pgsqlish'));
    });

    expect(array_column($on, 'query'))->toBe(['set local statement_timeout = 3000'])
        ->and($off)->toBe([]);
});
