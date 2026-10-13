<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\Eda\Console\ConsumeEventsCommand;
use Firefly\Eda\Console\ReplayDeadLettersCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers firefly:eda:consume and firefly:eda:dead-letters:replay via package auto-discovery (console only, so
 * nothing runs in an HTTP request), and ships the firefly_eda_dead_letters migration when
 * firefly.eda.dead-letter.store=database. The consume command is inert until a broker package binds an EventConsumer.
 */
final class EdaConsumerServiceProvider extends ServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public function boot(): void
    {
        if ($this->app->make('config')->get('firefly.eda.dead-letter.store') === 'database') {
            $this->loadMigrationsFrom(self::MIGRATIONS);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ConsumeEventsCommand::class, ReplayDeadLettersCommand::class]);
        }
    }
}
