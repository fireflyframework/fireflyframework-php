<?php

declare(strict_types=1);

use Firefly\Data\Transaction\Timeout\StatementTimeoutApplier;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;

/**
 * A driver-grammar connection over an in-memory sqlite PDO: under pretend() nothing executes, so the exact
 * statements each driver would receive show up in the query log.
 *
 * @template T of Connection
 *
 * @param  class-string<T>  $class
 * @return T
 */
function pretendConnection(string $class, string $driver): Connection
{
    return new $class(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => $driver, 'name' => 'pretend']);
}

it('issues SET LOCAL statement_timeout on pgsql, with nothing to restore', function () {
    $connection = pretendConnection(PostgresConnection::class, 'pgsql');
    $restore = null;

    $queries = $connection->pretend(function () use ($connection, &$restore): void {
        $restore = (new StatementTimeoutApplier)->apply($connection, 5);
    });

    expect(array_column($queries, 'query'))->toBe(['set local statement_timeout = 5000']);

    assert($restore instanceof Closure);
    $restored = $connection->pretend(static function () use ($restore): void {
        $restore();
    });
    expect($restored)->toBe([]);
});

it('sets and restores the session timeouts on mysql and mariadb', function (string $driver) {
    $connection = pretendConnection(MySqlConnection::class, $driver);

    $queries = $connection->pretend(function () use ($connection): void {
        $restore = (new StatementTimeoutApplier)->apply($connection, 5);
        $restore();
    });

    expect(array_column($queries, 'query'))->toBe([
        'select @@session.max_execution_time as execution, @@session.innodb_lock_wait_timeout as lock_wait',
        'set session max_execution_time = 5000',
        'set session innodb_lock_wait_timeout = 5',
        // pretend() answers the SELECT with nothing, so there is no previous value to restore — the applier
        // must not emit a restore for a value it never read.
    ]);
})->with(['mysql', 'mariadb']);

it('sets the busy timeout on sqlite through PDO and restores the configured one', function () {
    $connection = pretendConnection(SQLiteConnection::class, 'sqlite');

    $queries = $connection->pretend(function () use ($connection): void {
        $restore = (new StatementTimeoutApplier)->apply($connection, 5);
        $restore();
    });

    expect($queries)->toBe([]);
});

it('does nothing, safely, for a driver it does not know', function () {
    $connection = pretendConnection(SQLiteConnection::class, 'oracle');

    $queries = $connection->pretend(function () use ($connection): void {
        (new StatementTimeoutApplier)->apply($connection, 5)();
    });

    expect($queries)->toBe([]);
});
