<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/** An application with NO default database at all (`DB_CONNECTION=` empty): no indicator, not DOWN. */
abstract class NoDatabaseHealthCapstoneTestCase extends DefaultDbHealthCapstoneTestCase
{
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.default', null);
    }
}
