<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The queued-workflow capstone booted against a REAL server: the test's dataset names one of
 * IntegrationDatabase::servers(), and that server becomes the application's default connection — so the
 * execution checkpoints, the search attributes AND the `database` queue's jobs table all live on it, exactly
 * as in a deployed application whose worker shares the database. A server whose DSN variable or PDO driver is
 * missing SKIPS before the application boots.
 */
abstract class QueuedWorkflowIntegrationTestCase extends QueuedWorkflowCapstoneTestCase
{
    private const string CONNECTION = 'orchestration-it';

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
        $config->set('database.connections.'.self::CONNECTION, IntegrationDatabase::config($driver, $variable));
        $config->set('database.default', self::CONNECTION);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function server(): array
    {
        $name = $this->providedData()[0] ?? null;
        $servers = IntegrationDatabase::servers();
        if (! is_string($name) || ! isset($servers[$name])) {
            throw new \LogicException('A queued-workflow integration test needs a dataset naming one of IntegrationDatabase::servers().');
        }

        return $servers[$name];
    }
}
