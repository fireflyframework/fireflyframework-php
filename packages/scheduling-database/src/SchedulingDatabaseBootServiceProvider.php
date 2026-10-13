<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Illuminate\Support\ServiceProvider;

/** Ships the firefly_scheduling_locks migration through auto-discovery when firefly.scheduling.lock.provider=database. */
final class SchedulingDatabaseBootServiceProvider extends ServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public function boot(): void
    {
        if ($this->app->make('config')->get('firefly.scheduling.lock.provider') === 'database') {
            $this->loadMigrationsFrom(self::MIGRATIONS);
        }
    }
}
