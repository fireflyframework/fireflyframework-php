<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Firefly\Eda\Postgres\EdaPostgresServiceProvider;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\LumenTestCase;

/**
 * The lumen boot harness on a REAL Postgres connection (env-gated by FIREFLY_PG_DSN + pdo_pgsql), with
 * firefly.eda.provider=postgres so the genuine same-transaction outbox is exercised end-to-end on the driver it
 * targets: the OutboxPreCommitHook's in-tx INSERT + the driver-gated `SELECT pg_notify(...)` that only fires on pgsql.
 *
 * When Postgres is NOT configured the harness stays on the sqlite :memory: default (a harmless boot the round-trip
 * test skips before touching), so the class costs nothing in the default `composer test` run — which excludes the
 * integration group entirely.
 */
abstract class OutboxPostgresIntegrationTestCase extends LumenTestCase
{
    public const string CONNECTION = 'firefly_pg';

    /** True only when a real Postgres target is reachable (the pdo_pgsql extension is loaded and FIREFLY_PG_DSN is set). */
    public static function postgresAvailable(): bool
    {
        return extension_loaded('pdo_pgsql') && getenv('FIREFLY_PG_DSN') !== false;
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), EdaPostgresServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.eda.provider' => 'postgres'];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        if (! self::postgresAvailable()) {
            return; // leave the sqlite :memory: default in place — the round-trip test skips before it matters.
        }

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.connections.'.self::CONNECTION, self::postgresConfig());
        $config->set('database.default', self::CONNECTION);
    }

    protected function setUp(): void
    {
        parent::setUp(); // LumenTestCase::setUp -> migrate() (below) on the resolved default connection

        if (! self::postgresAvailable()) {
            return;
        }

        // Fresh outbox table on the real pgsql connection (drop-first: the test database may persist across runs).
        Schema::dropIfExists(OutboxSchema::TABLE);
        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }

    /** On real pgsql wipe-and-migrate so a persistent test database starts clean; on sqlite defer to the harness default. */
    protected function migrate(): void
    {
        if (! self::postgresAvailable()) {
            parent::migrate();

            return;
        }

        $path = dirname(__DIR__, 2).'/src/Infrastructure/Migration';
        if (is_dir($path)) {
            Artisan::call('migrate:fresh', ['--path' => $path, '--realpath' => true]);
        }
    }

    /**
     * A Laravel pgsql connection config parsed from FIREFLY_PG_DSN (host=…;port=…;dbname=…;user=…;password=…) —
     * mirrors packages/eda-postgres/tests/Integration/PostgresOutboxRoundTripTest::outbox_pg_config().
     *
     * @return array<string, mixed>
     */
    private static function postgresConfig(): array
    {
        $parts = [];
        foreach (explode(';', (string) getenv('FIREFLY_PG_DSN')) as $kv) {
            [$key, $value] = array_pad(explode('=', $kv, 2), 2, '');
            $parts[$key] = $value;
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

    protected function tearDown(): void
    {
        if (self::postgresAvailable()) {
            Schema::dropIfExists(OutboxSchema::TABLE);
        }

        parent::tearDown();
    }
}
