<?php

declare(strict_types=1);

namespace Firefly\Messaging;

use Firefly\Messaging\Console\ReplayDeadLettersCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers firefly:messaging:dead-letters:replay via package auto-discovery (console only, so nothing runs in an HTTP
 * request), and ships the firefly_messaging_dead_letters migration when firefly.messaging.dead-letter.store=database.
 * A plain provider for the reason EdaConsumerServiceProvider is one: MessagingServiceProvider extends
 * AutoConfiguration, whose final register() only records candidacy, and MessagingWiringProvider contributes boot
 * passes — neither is the place for commands and migrations.
 */
final class MessagingConsoleServiceProvider extends ServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public function boot(): void
    {
        if ($this->app->make('config')->get('firefly.messaging.dead-letter.store') === 'database') {
            $this->loadMigrationsFrom(self::MIGRATIONS);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ReplayDeadLettersCommand::class]);
        }
    }
}
