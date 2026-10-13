<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Illuminate\Support\ServiceProvider;

/**
 * Ships the outbox migrations (and, from the relay task on, firefly:outbox:relay) through auto-discovery when
 * firefly.eda.provider=database. EdaPostgresBootServiceProvider loads the SAME directory under provider=postgres —
 * there is one outbox table and one set of migrations whichever provider writes it.
 */
final class EdaDatabaseBootServiceProvider extends ServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public function boot(): void
    {
        if ($this->app->make('config')->get('firefly.eda.provider') !== 'database') {
            return;
        }

        $this->loadMigrationsFrom(self::MIGRATIONS);
    }
}
