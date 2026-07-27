<?php

declare(strict_types=1);

namespace Firefly\Cli;

use Firefly\Cli\Command\CacheCommand;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the firefly/cli Artisan commands via package auto-discovery (extra.laravel.providers) — NO
 * frozen-src edit. Commands are declared in commands([...]) and only bound in a console context.
 */
final class CliServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CacheCommand::class,
                // filled task-by-task: ClearCommand, AboutCommand, Routes/Health/Metrics,
                // Serve/Db, and the make:firefly-* family.
            ]);
        }
    }
}
