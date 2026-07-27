<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres;

use Illuminate\Support\ServiceProvider;

/** Ships the outbox migration + (Task 5) the relay/consumer commands via auto-discovery — no frozen edit. */
final class EdaPostgresBootServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
