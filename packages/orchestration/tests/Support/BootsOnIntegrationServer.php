<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Boots an orchestration capstone against a REAL server: the test's dataset names one of
 * IntegrationDatabase::lanes(), and that lane's database becomes the application's default connection — so the
 * execution checkpoints, the dead letters, the search attributes and (for the queued capstone) the `database`
 * queue's jobs table all live on it, exactly as in a deployed application. A lane whose DSN variable or PDO driver
 * is missing SKIPS, with IntegrationDsn's reason, before the application boots.
 */
trait BootsOnIntegrationServer
{
    protected function setUp(): void
    {
        $reason = IntegrationDatabase::skipReason($this->lane());
        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        parent::setUp();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.connections.orchestration-it', IntegrationDatabase::config($this->lane()));
        $config->set('database.default', 'orchestration-it');
    }

    private function lane(): string
    {
        $lane = $this->providedData()[0] ?? null;
        if (! is_string($lane) || ! in_array($lane, IntegrationDatabase::lanes(), true)) {
            throw new \LogicException('An orchestration integration test needs a dataset naming one of IntegrationDatabase::lanes().');
        }

        return $lane;
    }
}
