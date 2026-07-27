<?php

declare(strict_types=1);

namespace Firefly\Eda;

use Firefly\Eda\Console\ConsumeEventsCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the firefly:eda:consume Artisan command via package auto-discovery. Console-guarded so nothing runs in an
 * HTTP request. The command is inert until a broker package binds an EventConsumer.
 */
final class EdaConsumerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ConsumeEventsCommand::class]);
        }
    }
}
