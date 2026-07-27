<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Support;

use Firefly\Testing\Integration\RequiresDocker;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * The @group integration variant of the Foundation harness: boots the SAME cached-path persistence slice
 * (WidgetRepository derived query + FoundationWriter #[Transactional] commit) but against REAL Postgres instead of
 * sqlite :memory:. Docker-gated via RequiresDocker (skips when Docker is absent). The Postgres backend is described
 * by FIREFLY_PG_DSN (host=…;port=…;dbname=…;user=…;password=…) — the exact opt-in mechanism scheduling-postgres'
 * PgAdvisoryLockIntegrationTest uses — wrapped in a duck-typed container and mapped into the Laravel `testing`
 * connection through firefly/testing's fireflyConfigFor() @ServiceConnection helper (the same shape a
 * testcontainers container exposes, so dropping in testcontainers/testcontainers later is a one-line swap). Only
 * the DB connection differs from the sqlite pass; every other layer boots identically.
 */
abstract class FoundationFlowPostgresTestCase extends FoundationFlowTestCase
{
    use RequiresDocker;

    /** The Postgres backend as a duck-typed container (fireflyConfigFor() reads its getHost()/…/getDatabase()). */
    private ?object $pgContainer = null;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();
        $this->pgContainer = $this->resolvePostgres();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            Schema::dropIfExists('widgets');
        }
        $this->pgContainer = null;

        parent::tearDown();
    }

    /**
     * Override FireflyDatabaseTestCase's sqlite default with the Postgres backend, mapped via fireflyConfigFor()
     * (the same duck-typed @ServiceConnection map RequiresDockerTest exercises).
     */
    protected function defineSqliteMemory(Application $app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => 5432,
            'database' => 'postgres',
            'username' => 'postgres',
            'password' => '',
            'charset' => 'utf8',
            'prefix' => '',
            'schema' => 'public',
        ]);

        // fireflyConfigFor() returns the flat database.connections.testing.{host,port,username,password,database}
        // keys for the resolved backend, overlaying the placeholders above.
        foreach (fireflyConfigFor($this->pgContainer ?? new stdClass) as $key => $value) {
            $config->set($key, $value);
        }
    }

    /**
     * Resolve a real Postgres backend from FIREFLY_PG_DSN, or skip. Docker is required (skipUnlessDocker() already
     * ran) to stand up that Postgres; the DSN then points this test at it. A DSN-less environment skips cleanly so
     * the opt-in @group integration pass never hard-fails.
     */
    private function resolvePostgres(): object
    {
        $dsn = getenv('FIREFLY_PG_DSN');
        if (! is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('Set FIREFLY_PG_DSN (host=…;port=…;dbname=…;user=…;password=…) to run the @group integration Postgres pass.');
        }

        $parts = [];
        foreach (explode(';', $dsn) as $kv) {
            [$key, $value] = array_pad(explode('=', $kv, 2), 2, '');
            $parts[$key] = $value;
        }

        return new class($parts)
        {
            /** @param array<string,string> $parts */
            public function __construct(private array $parts) {}

            public function getHost(): string
            {
                return $this->parts['host'] ?? '127.0.0.1';
            }

            public function getMappedPort(): int
            {
                return (int) ($this->parts['port'] ?? 5432);
            }

            public function getUsername(): string
            {
                return $this->parts['user'] ?? 'postgres';
            }

            public function getPassword(): string
            {
                return $this->parts['password'] ?? '';
            }

            public function getDatabase(): string
            {
                return $this->parts['dbname'] ?? 'postgres';
            }
        };
    }
}
