<?php

declare(strict_types=1);

use Firefly\Data\Capability\DatabaseCapabilities;
use Illuminate\Database\SQLiteConnection;

/**
 * Locking SQL is chosen by what the server SPEAKS, not by the driver's name (PROGRAM.md rule 3): `mysql` names both a
 * MySQL 5.7 that has no SKIP LOCKED and an 8.4 that has it, and a `mysql`-configured connection may well be talking to
 * MariaDB. The version strings below are the ones the shared containers report through PDO::ATTR_SERVER_VERSION
 * (pgsql 16.15, mysql 8.4.11, mariadb 11.8.9-MariaDB-ubu2404) plus the historical shapes the probe must also read.
 */
it('reads SKIP LOCKED support from the driver and the server version', function (string $driver, string $version, bool $skipLocked, bool $maria, string $numeric) {
    $capabilities = DatabaseCapabilities::fromVersion($driver, $version);

    expect($capabilities->supportsSkipLocked())->toBe($skipLocked)
        ->and($capabilities->mariaDb)->toBe($maria)
        ->and($capabilities->version())->toBe($numeric);
})->with([
    'postgres 16' => ['pgsql', '16.15', true, false, '16.15'],
    'postgres, version unreadable' => ['pgsql', '', true, false, '0'],
    'mysql 8.4' => ['mysql', '8.4.11', true, false, '8.4.11'],
    'mysql 8.0.1, the first with SKIP LOCKED' => ['mysql', '8.0.1', true, false, '8.0.1'],
    'mysql 8.0.0' => ['mysql', '8.0.0', false, false, '8.0.0'],
    'mysql 5.7' => ['mysql', '5.7.44-log', false, false, '5.7.44'],
    'mariadb 11 on the mariadb driver' => ['mariadb', '11.8.9-MariaDB-ubu2404', true, true, '11.8.9'],
    'mariadb 10.6' => ['mariadb', '10.6.18-MariaDB', true, true, '10.6.18'],
    'mariadb 10.5' => ['mariadb', '10.5.25-MariaDB', false, true, '10.5.25'],
    'mariadb behind a mysql driver, replication prefix' => ['mysql', '5.5.5-10.11.6-MariaDB-1:10.11.6+maria~ubu2204', true, true, '10.11.6'],
    'mariadb 10.4 behind a mysql driver' => ['mysql', '5.5.5-10.4.34-MariaDB', false, true, '10.4.34'],
    'sqlite' => ['sqlite', '3.46.1', false, false, '3.46.1'],
    'sqlsrv' => ['sqlsrv', '16.00.4135', false, false, '16.00.4135'],
    'an unknown driver' => ['oracle', '23.4', false, false, '23.4'],
]);

it('knows READPAST is a SQL Server table hint and nothing else', function () {
    expect(DatabaseCapabilities::fromVersion('sqlsrv', '16.00.4135')->supportsReadPast())->toBeTrue()
        ->and(DatabaseCapabilities::fromVersion('pgsql', '16.15')->supportsReadPast())->toBeFalse()
        ->and(DatabaseCapabilities::fromVersion('mariadb', '11.4.2-MariaDB')->isMySqlFamily())->toBeTrue()
        ->and(DatabaseCapabilities::fromVersion('sqlsrv', '16.00.4135')->isMySqlFamily())->toBeFalse()
        ->and(DatabaseCapabilities::fromVersion('mysql', '8.4.11')->describe())->toBe('mysql 8.4.11')
        ->and(DatabaseCapabilities::fromVersion('mysql', '')->describe())->toBe('mysql (unknown version)');
});

it('probes a live connection without throwing, reading the version PDO reports', function () {
    $capabilities = DatabaseCapabilities::of(new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:'));

    expect($capabilities->driver)->toBe('sqlite')
        ->and($capabilities->serverVersion)->toMatch('/^3\.\d+/')
        ->and($capabilities->supportsSkipLocked())->toBeFalse();
});
