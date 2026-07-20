<?php

declare(strict_types=1);

use Firefly\Scheduling\Postgres\PgAdvisoryLock;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/**
 * Real Postgres advisory-lock behaviour. Env-gated (NOT a hard CI gate — the PHP ecosystem's PG test
 * infra is thin): requires pdo_pgsql + FIREFLY_PG_DSN (host=…;port=…;dbname=…;user=…;password=…).
 */
it('grants a lock to the first session and refuses the second until release', function () {
    // Two independent connections; a session-level advisory lock is exclusive across sessions.
    config(['database.connections.firefly_pg_a' => firefly_pg_config(), 'database.connections.firefly_pg_b' => firefly_pg_config()]);

    $a = new PgAdvisoryLock('firefly_pg_a');
    $b = new PgAdvisoryLock('firefly_pg_b');
    $name = 'firefly:test:'.bin2hex(random_bytes(4));

    expect($a->tryAcquire($name, 30.0))->toBeTrue()
        ->and($b->tryAcquire($name, 30.0))->toBeFalse();

    $a->release($name);
    DB::connection('firefly_pg_a')->disconnect();

    expect($b->tryAcquire($name, 30.0))->toBeTrue();
    $b->release($name);
    DB::connection('firefly_pg_b')->disconnect();
})->skip(
    ! extension_loaded('pdo_pgsql') || getenv('FIREFLY_PG_DSN') === false,
    'requires pdo_pgsql + FIREFLY_PG_DSN',
);

/** @return array<string,mixed> a Laravel pgsql connection config parsed from FIREFLY_PG_DSN */
function firefly_pg_config(): array
{
    $parts = [];
    foreach (explode(';', (string) getenv('FIREFLY_PG_DSN')) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$k] = $v;
    }

    return [
        'driver' => 'pgsql',
        'host' => $parts['host'] ?? '127.0.0.1',
        'port' => $parts['port'] ?? '5432',
        'database' => $parts['dbname'] ?? 'postgres',
        'username' => $parts['user'] ?? 'postgres',
        'password' => $parts['password'] ?? '',
    ];
}
