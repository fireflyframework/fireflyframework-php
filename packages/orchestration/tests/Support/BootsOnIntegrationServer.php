<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Boots an orchestration capstone against a REAL server: the test's dataset names one of
 * IntegrationDatabase::servers(), and that server becomes the application's default connection — so the
 * execution checkpoints, the dead letters, the search attributes and (for the queued capstone) the `database`
 * queue's jobs table all live on it, exactly as in a deployed application. A server whose DSN variable or PDO
 * driver is missing SKIPS before the application boots.
 */
trait BootsOnIntegrationServer
{
    protected function setUp(): void
    {
        [, $variable, $extension] = $this->server();
        $reason = IntegrationDatabase::skipReason($variable, $extension);
        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        parent::setUp();
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        [$driver, $variable] = $this->server();
        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.connections.orchestration-it', IntegrationDatabase::config($driver, $variable));
        $config->set('database.default', 'orchestration-it');
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function server(): array
    {
        $name = $this->providedData()[0] ?? null;
        $servers = IntegrationDatabase::servers();
        if (! is_string($name) || ! isset($servers[$name])) {
            throw new \LogicException('An orchestration integration test needs a dataset naming one of IntegrationDatabase::servers().');
        }

        return $servers[$name];
    }
}
