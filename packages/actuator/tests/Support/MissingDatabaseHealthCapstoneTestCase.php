<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * The default connection points at a sqlite file that does not exist. Nothing at boot touches the database,
 * so the application starts; the first `SELECT 1` — the health probe — fails, and the indicator must say DOWN.
 */
abstract class MissingDatabaseHealthCapstoneTestCase extends DefaultDbHealthCapstoneTestCase
{
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.connections.testing.database', '/nonexistent/firefly/health-'.bin2hex(random_bytes(4)).'.sqlite');
    }
}
