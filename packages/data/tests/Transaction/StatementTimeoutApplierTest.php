<?php

declare(strict_types=1);

use Firefly\Data\Tests\Support\SessionVariableConnection;
use Firefly\Data\Transaction\Timeout\StatementTimeoutApplier;
use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
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

/**
 * apply() under pretend(), then the restore it returned under a second pretend(): the statements of each
 * phase, so a test can say what was set AND what was put back.
 *
 * @return array{applied: list<string>, restored: list<string>}
 */
function applyAndRestore(Connection $connection, int $seconds): array
{
    $restore = null;
    $applied = $connection->pretend(function () use ($connection, $seconds, &$restore): void {
        $restore = (new StatementTimeoutApplier)->apply($connection, $seconds);
    });

    assert($restore instanceof Closure);
    $restored = $connection->pretend(static function () use ($restore): void {
        $restore();
    });

    return ['applied' => array_column($applied, 'query'), 'restored' => array_column($restored, 'query')];
}

it('issues SET LOCAL statement_timeout on pgsql, with nothing to restore', function () {
    $phases = applyAndRestore(pretendConnection(PostgresConnection::class, 'pgsql'), 5);

    expect($phases['applied'])->toBe(['set local statement_timeout = 5000'])
        ->and($phases['restored'])->toBe([]);
});

it('sets max_execution_time in milliseconds and innodb_lock_wait_timeout in seconds on mysql', function () {
    $phases = applyAndRestore(pretendConnection(MySqlConnection::class, 'mysql'), 5);

    expect($phases['applied'])->toBe([
        'select @@session.max_execution_time as value',
        'set session max_execution_time = 5000',
        'select @@session.innodb_lock_wait_timeout as value',
        'set session innodb_lock_wait_timeout = 5',
    ])
        // pretend() answers the SELECTs with nothing, so there is no previous value to restore — the applier
        // must not emit a restore for a value it never read.
        ->and($phases['restored'])->toBe([]);
});

it('sets max_statement_time in SECONDS on mariadb, which has no max_execution_time variable', function (Connection $connection) {
    $phases = applyAndRestore($connection, 5);

    expect($phases['applied'])->toBe([
        'select @@session.max_statement_time as value',
        'set session max_statement_time = 5',
        'select @@session.innodb_lock_wait_timeout as value',
        'set session innodb_lock_wait_timeout = 5',
    ])
        ->and($phases['restored'])->toBe([]);
})->with([
    'the mariadb driver' => fn (): Connection => pretendConnection(MariaDbConnection::class, 'mariadb'),
    'a mysql driver whose server is mariadb' => fn (): Connection => new SessionVariableConnection('mysql', maria: true),
]);

it('restores the previous mysql session values, last set first, in their own types', function () {
    $connection = new SessionVariableConnection('mysql', ['max_execution_time' => 0, 'innodb_lock_wait_timeout' => 50]);

    $phases = applyAndRestore($connection, 5);

    expect($phases['applied'])->toBe([
        'select @@session.max_execution_time as value',
        'set session max_execution_time = 5000',
        'select @@session.innodb_lock_wait_timeout as value',
        'set session innodb_lock_wait_timeout = 5',
    ])
        ->and($phases['restored'])->toBe([
            'set session innodb_lock_wait_timeout = 50',
            'set session max_execution_time = 0',
        ]);
});

it('restores a fractional mariadb max_statement_time as the double it is, and an integral one without decimals', function (int|float|string $previous, string $literal) {
    $connection = new SessionVariableConnection('mariadb', ['max_statement_time' => $previous, 'innodb_lock_wait_timeout' => '50']);

    expect(applyAndRestore($connection, 5)['restored'])->toBe([
        'set session innodb_lock_wait_timeout = 50',
        'set session max_statement_time = '.$literal,
    ]);
})->with([
    'the default, as mariadb reports it' => ['0.000000', '0'],
    'a fraction of a second' => ['2.500000', '2.5'],
    'a native float' => [0.25, '0.25'],
    'a whole number' => [30, '30'],
]);

it('applies the variable the server knows when it refuses the other, and restores only what it set', function () {
    $connection = new SessionVariableConnection('mysql', ['innodb_lock_wait_timeout' => 50], unknown: ['max_execution_time']);

    $phases = applyAndRestore($connection, 5);

    expect($phases['applied'])->toBe([
        'select @@session.innodb_lock_wait_timeout as value',
        'set session innodb_lock_wait_timeout = 5',
    ])
        ->and($phases['restored'])->toBe(['set session innodb_lock_wait_timeout = 50']);
});

it('sets nothing and restores nothing, without failing, when the server knows neither variable', function () {
    $connection = new SessionVariableConnection('mysql', unknown: ['max_execution_time', 'innodb_lock_wait_timeout']);

    $phases = applyAndRestore($connection, 5);

    expect($phases['applied'])->toBe([])
        ->and($phases['restored'])->toBe([]);
});

it('answers a no-op when the driver refuses the SET outright, instead of failing the transaction', function () {
    $connection = new class(new PDO('sqlite::memory:'), 'pretend', '', ['driver' => 'pgsql', 'name' => 'pretend']) extends PostgresConnection
    {
        /**
         * @param  string  $query
         * @param  array<int|string, mixed>  $bindings
         */
        public function statement($query, $bindings = [])
        {
            throw new RuntimeException('permission denied to set parameter "statement_timeout"');
        }
    };

    $restore = (new StatementTimeoutApplier)->apply($connection, 5);

    $restored = $connection->pretend(static function () use ($restore): void {
        $restore();
    });

    expect($restored)->toBe([]);
});

it('sets the busy timeout on sqlite through PDO and restores the configured one', function () {
    $phases = applyAndRestore(pretendConnection(SQLiteConnection::class, 'sqlite'), 5);

    expect($phases['applied'])->toBe([])
        ->and($phases['restored'])->toBe([]);
});

it('does nothing, safely, for a driver it does not know', function () {
    $phases = applyAndRestore(pretendConnection(SQLiteConnection::class, 'oracle'), 5);

    expect($phases['applied'])->toBe([])
        ->and($phases['restored'])->toBe([]);
});
