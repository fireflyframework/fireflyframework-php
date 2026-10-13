<?php

declare(strict_types=1);

use Firefly\Scheduling\Postgres\PgAdvisoryLock;
use Firefly\Testing\Integration\IntegrationDsn;
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
    IntegrationDsn::skipReason('pgsql') !== null,
    (string) IntegrationDsn::skipReason('pgsql'),
)->group('integration');

/** @return array<string,mixed> a Laravel pgsql connection config from FIREFLY_PG_DSN */
function firefly_pg_config(): array
{
    return IntegrationDsn::connectionConfig('pgsql');
}
