<?php

declare(strict_types=1);

use Firefly\Data\Exception\DriverErrorTable;

// driver, driver error code, the kind the translator must produce
dataset('driver codes', [
    'sqlite 19 constraint' => ['sqlite', 19, DriverErrorTable::INTEGRITY],
    'sqlite 1555 primary key' => ['sqlite', 1555, DriverErrorTable::DUPLICATE_KEY],
    'sqlite 2067 unique' => ['sqlite', 2067, DriverErrorTable::DUPLICATE_KEY],
    'sqlite 5 busy' => ['sqlite', 5, DriverErrorTable::LOCK],
    'sqlite 6 locked' => ['sqlite', 6, DriverErrorTable::LOCK],
    'sqlite 1 sql error' => ['sqlite', 1, DriverErrorTable::GRAMMAR],
    'sqlite 14 cannot open' => ['sqlite', 14, DriverErrorTable::RESOURCE],
    'mysql 1062 duplicate entry' => ['mysql', 1062, DriverErrorTable::DUPLICATE_KEY],
    'mysql 1213 deadlock' => ['mysql', 1213, DriverErrorTable::DEADLOCK],
    'mysql 1205 lock wait timeout' => ['mysql', 1205, DriverErrorTable::LOCK],
    'mysql 3024 max_execution_time' => ['mysql', 3024, DriverErrorTable::TIMEOUT],
    'mysql 2002 cannot connect' => ['mysql', 2002, DriverErrorTable::RESOURCE],
    'mysql 2006 server gone away' => ['mysql', 2006, DriverErrorTable::TRANSIENT],
    'mysql 2013 lost connection' => ['mysql', 2013, DriverErrorTable::TRANSIENT],
    'mysql 1064 syntax' => ['mysql', 1064, DriverErrorTable::GRAMMAR],
    'mysql 1146 no such table' => ['mysql', 1146, DriverErrorTable::GRAMMAR],
    'mysql 1054 unknown column' => ['mysql', 1054, DriverErrorTable::GRAMMAR],
    'mysql 1048 not null' => ['mysql', 1048, DriverErrorTable::INTEGRITY],
    'mysql 1451 fk parent' => ['mysql', 1451, DriverErrorTable::INTEGRITY],
    'mysql 1452 fk child' => ['mysql', 1452, DriverErrorTable::INTEGRITY],
    'mariadb 1062 duplicate entry' => ['mariadb', 1062, DriverErrorTable::DUPLICATE_KEY],
    'mariadb 1213 deadlock' => ['mariadb', 1213, DriverErrorTable::DEADLOCK],
    'mariadb 1205 lock wait timeout' => ['mariadb', 1205, DriverErrorTable::LOCK],
    'mariadb 3024 max_execution_time' => ['mariadb', 3024, DriverErrorTable::TIMEOUT],
    'mariadb 2002 cannot connect' => ['mariadb', 2002, DriverErrorTable::RESOURCE],
    'mariadb 2006 server gone away' => ['mariadb', 2006, DriverErrorTable::TRANSIENT],
    'mariadb 2013 lost connection' => ['mariadb', 2013, DriverErrorTable::TRANSIENT],
    'mariadb 1064 syntax' => ['mariadb', 1064, DriverErrorTable::GRAMMAR],
    'mariadb 1146 no such table' => ['mariadb', 1146, DriverErrorTable::GRAMMAR],
    'mariadb 1054 unknown column' => ['mariadb', 1054, DriverErrorTable::GRAMMAR],
    'mariadb 1048 not null' => ['mariadb', 1048, DriverErrorTable::INTEGRITY],
    'mariadb 1451 fk parent' => ['mariadb', 1451, DriverErrorTable::INTEGRITY],
    'mariadb 1452 fk child' => ['mariadb', 1452, DriverErrorTable::INTEGRITY],
    'sqlsrv 2627 unique constraint' => ['sqlsrv', 2627, DriverErrorTable::DUPLICATE_KEY],
    'sqlsrv 2601 unique index' => ['sqlsrv', 2601, DriverErrorTable::DUPLICATE_KEY],
    'sqlsrv 1205 deadlock victim' => ['sqlsrv', 1205, DriverErrorTable::DEADLOCK],
    'sqlsrv 547 constraint' => ['sqlsrv', 547, DriverErrorTable::INTEGRITY],
    'sqlsrv 515 not null' => ['sqlsrv', 515, DriverErrorTable::INTEGRITY],
    'sqlsrv 1222 lock request timeout' => ['sqlsrv', 1222, DriverErrorTable::LOCK],
    'sqlsrv 208 invalid object' => ['sqlsrv', 208, DriverErrorTable::GRAMMAR],
    'sqlsrv 102 syntax' => ['sqlsrv', 102, DriverErrorTable::GRAMMAR],
]);

// exact SQLSTATE, kind
dataset('sqlstates', [
    '23505 unique violation' => ['23505', DriverErrorTable::DUPLICATE_KEY],
    '23503 fk violation' => ['23503', DriverErrorTable::INTEGRITY],
    '23502 not null' => ['23502', DriverErrorTable::INTEGRITY],
    '23514 check' => ['23514', DriverErrorTable::INTEGRITY],
    '23000 generic integrity' => ['23000', DriverErrorTable::INTEGRITY],
    '40P01 deadlock' => ['40P01', DriverErrorTable::DEADLOCK],
    '40001 serialization failure' => ['40001', DriverErrorTable::TRANSIENT],
    '55P03 lock not available' => ['55P03', DriverErrorTable::LOCK],
    '57014 query canceled' => ['57014', DriverErrorTable::TIMEOUT],
    '42601 syntax' => ['42601', DriverErrorTable::GRAMMAR],
    '42P01 undefined table' => ['42P01', DriverErrorTable::GRAMMAR],
    '42703 undefined column' => ['42703', DriverErrorTable::GRAMMAR],
    '42S02 base table not found' => ['42S02', DriverErrorTable::GRAMMAR],
    '42S22 column not found' => ['42S22', DriverErrorTable::GRAMMAR],
    '42000 syntax or access' => ['42000', DriverErrorTable::GRAMMAR],
    '08001 cannot connect' => ['08001', DriverErrorTable::RESOURCE],
    '08003 connection does not exist' => ['08003', DriverErrorTable::RESOURCE],
    '08004 rejected' => ['08004', DriverErrorTable::RESOURCE],
    '08006 connection failure' => ['08006', DriverErrorTable::RESOURCE],
    '08007 unknown transaction resolution' => ['08007', DriverErrorTable::RESOURCE],
    '08S01 communication link failure' => ['08S01', DriverErrorTable::TRANSIENT],
]);

// SQLSTATE class, kind — Spring's SQLStateSQLExceptionTranslator buckets
dataset('sqlstate classes', [
    '07 dynamic sql' => ['07', DriverErrorTable::GRAMMAR],
    '21 cardinality' => ['21', DriverErrorTable::GRAMMAR],
    '2A direct sql syntax' => ['2A', DriverErrorTable::GRAMMAR],
    '37 dynamic sql syntax' => ['37', DriverErrorTable::GRAMMAR],
    '42 syntax or access' => ['42', DriverErrorTable::GRAMMAR],
    '65 oracle syntax' => ['65', DriverErrorTable::GRAMMAR],
    '22 data exception' => ['22', DriverErrorTable::INTEGRITY],
    '23 integrity constraint' => ['23', DriverErrorTable::INTEGRITY],
    '27 triggered data change' => ['27', DriverErrorTable::INTEGRITY],
    '44 with check option' => ['44', DriverErrorTable::INTEGRITY],
    '08 connection' => ['08', DriverErrorTable::RESOURCE],
    '53 insufficient resources' => ['53', DriverErrorTable::RESOURCE],
    '54 program limit' => ['54', DriverErrorTable::RESOURCE],
    '57 operator intervention' => ['57', DriverErrorTable::RESOURCE],
    '58 system error' => ['58', DriverErrorTable::RESOURCE],
    '40 transaction rollback' => ['40', DriverErrorTable::TRANSIENT],
    '61 oracle lock' => ['61', DriverErrorTable::TRANSIENT],
]);

it('maps a driver error code', function (string $driver, int $code, string $kind) {
    // The SQLSTATE is the vaguest possible one, so only the driver row can produce the answer.
    expect(DriverErrorTable::kindFor($driver, $code, 'HY000'))->toBe($kind);
})->with('driver codes');

it('maps an exact SQLSTATE when the driver is unknown or has no row for the code', function (string $sqlState, string $kind) {
    expect(DriverErrorTable::kindFor(null, null, $sqlState))->toBe($kind)
        ->and(DriverErrorTable::kindFor('pgsql', 7, $sqlState))->toBe($kind);
})->with('sqlstates');

it('falls back to the SQLSTATE class', function (string $class, string $kind) {
    expect(DriverErrorTable::kindFor(null, null, $class.'ZZ'))->toBe($kind);
})->with('sqlstate classes');

it('prefers the driver code over the SQLSTATE and answers null for the unknown', function () {
    // mysql 1062 arrives as SQLSTATE 23000; the driver row is what makes it a DUPLICATE, not an INTEGRITY.
    expect(DriverErrorTable::kindFor('mysql', 1062, '23000'))->toBe(DriverErrorTable::DUPLICATE_KEY)
        ->and(DriverErrorTable::kindFor('sqlsrv', 1205, 'HY000'))->toBe(DriverErrorTable::DEADLOCK)
        ->and(DriverErrorTable::kindFor('mysql', 1205, 'HY000'))->toBe(DriverErrorTable::LOCK)
        ->and(DriverErrorTable::kindFor(null, 1062, null))->toBeNull()
        ->and(DriverErrorTable::kindFor('sqlite', 999, 'HY000'))->toBeNull()
        ->and(DriverErrorTable::kindFor('oracle', 1, 'ZZ999'))->toBeNull();
});

it('has a test row for every table row', function () {
    $driverRows = 0;
    foreach (DriverErrorTable::DRIVER_CODES as $codes) {
        $driverRows += count($codes);
    }

    expect($driverRows)->toBe(41)
        ->and(count(DriverErrorTable::SQLSTATES))->toBe(21)
        ->and(count(DriverErrorTable::SQLSTATE_CLASSES))->toBe(17);
});
