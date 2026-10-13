<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Eda\Database\EdaDatabaseBootServiceProvider;
use Firefly\Eda\Postgres\Console\OutboxRelayCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Ships the outbox migrations (firefly/eda-database's, the one outbox table) and the OPTIONAL firefly:outbox:relay
 * command under provider=postgres via auto-discovery.
 */
final class EdaPostgresBootServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->make('config')->get('firefly.eda.provider') !== 'postgres') {
            return;
        }

        $this->loadMigrationsFrom(EdaDatabaseBootServiceProvider::MIGRATIONS);

        if ($this->app->runningInConsole()) {
            $this->commands([OutboxRelayCommand::class]);
        }
    }
}
