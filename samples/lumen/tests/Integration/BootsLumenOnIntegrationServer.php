<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Artisan;
use LogicException;

/**
 * Boots a Lumen harness against a REAL server: the test's dataset names one of IntegrationDsn::lanes(), and that
 * lane's database becomes the application's default connection, so the wallets, the ledger and the outbox all live
 * on it, exactly as in a deployed application. A lane whose DSN variable or PDO driver is missing SKIPS, with
 * IntegrationDsn's reason, before the application boots. The orchestration package's BootsOnIntegrationServer is the
 * precedent.
 */
trait BootsLumenOnIntegrationServer
{
    protected function setUp(): void
    {
        $reason = IntegrationDsn::skipReason($this->lane());
        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        parent::setUp();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        $lane = $this->lane();
        $overrides = $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('lumen-outbox')] : [];

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.connections.lumen-it', IntegrationDsn::connectionConfig($lane, $overrides));
        $config->set('database.default', 'lumen-it');
    }

    /** A server database keeps the last run's rows, so every test starts from freshly migrated sample tables. */
    protected function migrate(): void
    {
        Artisan::call('migrate:fresh', ['--path' => dirname(__DIR__, 2).'/src/Infrastructure/Migration', '--realpath' => true]);
    }

    private function lane(): string
    {
        $lane = $this->providedData()[0] ?? null;
        if (! is_string($lane) || ! in_array($lane, IntegrationDsn::lanes(), true)) {
            throw new LogicException('A Lumen integration test needs a dataset naming one of IntegrationDsn::lanes().');
        }

        return $lane;
    }
}
