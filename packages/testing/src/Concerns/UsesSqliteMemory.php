<?php

declare(strict_types=1);

namespace Firefly\Testing\Concerns;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;

/** Binds the shared sqlite :memory: `testing` connection as the default — the 6-copy Family A block, absorbed. */
trait UsesSqliteMemory
{
    protected function defineSqliteMemory(Application $app): void
    {
        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
