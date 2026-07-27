<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Firefly\Eda\Postgres\Console\OutboxRelayCommand;
use Illuminate\Support\ServiceProvider;

/** Ships the outbox migration + (Task 5) the OPTIONAL firefly:outbox:relay command via auto-discovery — no frozen edit. */
final class EdaPostgresBootServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([OutboxRelayCommand::class]);
        }
    }
}
