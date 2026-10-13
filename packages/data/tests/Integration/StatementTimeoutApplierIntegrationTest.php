<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Transaction\Timeout\StatementTimeoutApplier;
use Firefly\Kernel\Exception\Infrastructure\CannotAcquireLockException;
use Firefly\Kernel\Exception\Infrastructure\QueryTimeoutException;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * The per-driver half of a transaction timeout against the servers that enforce it: pgsql interrupts a slow statement
 * (57014), mariadb interrupts any statement past max_statement_time (1969), mysql interrupts a read past
 * max_execution_time, sqlsrv fails a lock wait past LOCK_TIMEOUT (1222) — each translated into the kernel family — and
 * every SESSION-scoped value is put back afterwards, so a pooled connection does not inherit one unit of work's budget.
 */
function timeoutValue(Connection $connection, string $sql): mixed
{
    $row = $connection->selectOne($sql);

    return is_object($row) ? (get_object_vars($row)['value'] ?? null) : null;
}

function timeoutTranslated(Closure $statement, string $driver): ?Throwable
{
    try {
        $statement();
    } catch (Throwable $e) {
        return (new PersistenceExceptionTranslator)->translate($e, $driver);
    }

    return null;
}

it('interrupts a slow statement on pgsql with SET LOCAL statement_timeout', function () {
    $connection = IntegrationDsn::connect('pgsql', 'timeout-pgsql');
    $connection->beginTransaction();
    $restore = (new StatementTimeoutApplier)->apply($connection, 1);

    $started = microtime(true);
    $error = timeoutTranslated(static fn () => $connection->select('select pg_sleep(3)'), 'pgsql');

    expect($error)->toBeInstanceOf(QueryTimeoutException::class)
        ->and(microtime(true) - $started)->toBeLessThan(2.5);
    $connection->rollBack();
    $restore();
    $connection->disconnect();
})->group('integration')->skip(IntegrationDsn::skipReason('pgsql') !== null, (string) IntegrationDsn::skipReason('pgsql'));

it('interrupts any statement past max_statement_time on mariadb, then restores it', function () {
    $connection = IntegrationDsn::connect('mariadb', 'timeout-mariadb');
    $before = timeoutValue($connection, 'select @@session.max_statement_time as value');
    $connection->beginTransaction();
    $restore = (new StatementTimeoutApplier)->apply($connection, 1);

    $error = timeoutTranslated(static fn () => $connection->select('select sleep(3)'), 'mariadb');
    $connection->rollBack();
    $restore();

    expect($error)->toBeInstanceOf(QueryTimeoutException::class)
        ->and(timeoutValue($connection, 'select @@session.max_statement_time as value'))->toEqual($before);
    $connection->disconnect();
})->group('integration')->skip(IntegrationDsn::skipReason('mariadb') !== null, (string) IntegrationDsn::skipReason('mariadb'));

it('bounds a read with max_execution_time on mysql, then restores both session variables', function () {
    $connection = IntegrationDsn::connect('mysql', 'timeout-mysql');
    $executionBefore = timeoutValue($connection, 'select @@session.max_execution_time as value');
    $lockBefore = timeoutValue($connection, 'select @@session.innodb_lock_wait_timeout as value');
    $connection->beginTransaction();
    $restore = (new StatementTimeoutApplier)->apply($connection, 1);

    expect(timeoutValue($connection, 'select @@session.max_execution_time as value'))->toEqual(1000)
        ->and(timeoutValue($connection, 'select @@session.innodb_lock_wait_timeout as value'))->toEqual(1);

    // MySQL interrupts SLEEP() inside a timed-out SELECT either with ER_QUERY_TIMEOUT (3024) or by returning 1; both
    // end the statement inside the budget, which is the property that matters.
    $started = microtime(true);
    $error = timeoutTranslated(static fn () => $connection->select('select sleep(3) as slept'), 'mysql');
    expect(microtime(true) - $started)->toBeLessThan(2.5)
        ->and($error === null || $error instanceof QueryTimeoutException)->toBeTrue();

    $connection->rollBack();
    $restore();
    expect(timeoutValue($connection, 'select @@session.max_execution_time as value'))->toEqual($executionBefore)
        ->and(timeoutValue($connection, 'select @@session.innodb_lock_wait_timeout as value'))->toEqual($lockBefore);
    $connection->disconnect();
})->group('integration')->skip(IntegrationDsn::skipReason('mysql') !== null, (string) IntegrationDsn::skipReason('mysql'));

it('fails a lock wait past LOCK_TIMEOUT on sqlsrv, then restores the session value', function () {
    $holder = IntegrationDsn::connect('sqlsrv', 'timeout-holder');
    $waiter = IntegrationDsn::connect('sqlsrv', 'timeout-waiter');
    $schema = $holder->getSchemaBuilder();
    $schema->dropIfExists('firefly_timeout_probe');
    $schema->create('firefly_timeout_probe', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->string('name');
    });
    $holder->table('firefly_timeout_probe')->insert(['id' => 1, 'name' => 'a']);

    try {
        $holder->beginTransaction();
        $holder->table('firefly_timeout_probe')->where('id', 1)->update(['name' => 'held']);

        $before = timeoutValue($waiter, 'select @@lock_timeout as value');
        $waiter->beginTransaction();
        $restore = (new StatementTimeoutApplier)->apply($waiter, 1);
        $error = timeoutTranslated(static fn () => $waiter->table('firefly_timeout_probe')->where('id', 1)->update(['name' => 'waiter']), 'sqlsrv');
        $waiter->rollBack();
        $restore();

        expect($error)->toBeInstanceOf(CannotAcquireLockException::class)
            ->and(timeoutValue($waiter, 'select @@lock_timeout as value'))->toEqual($before);
    } finally {
        $holder->rollBack();
        $schema->dropIfExists('firefly_timeout_probe');
        $holder->disconnect();
        $waiter->disconnect();
    }
})->group('integration')->skip(IntegrationDsn::skipReason('sqlsrv') !== null, (string) IntegrationDsn::skipReason('sqlsrv'));

it('sets and restores the busy timeout on a sqlite file without failing the unit of work', function () {
    $path = IntegrationDsn::sqlitePath('timeout');
    $connection = IntegrationDsn::connect('sqlite', 'timeout-sqlite', ['database' => $path]);
    $connection->beginTransaction();
    $restore = (new StatementTimeoutApplier)->apply($connection, 1);

    expect($connection->selectOne('select 1 as one'))->not->toBeNull();
    $connection->commit();
    $restore();
    $connection->disconnect();
    foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
})->group('integration');
