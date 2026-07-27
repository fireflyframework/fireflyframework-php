<?php

declare(strict_types=1);

namespace Firefly\Cli;

use Firefly\Cli\Command\AboutCommand;
use Firefly\Cli\Command\CacheCommand;
use Firefly\Cli\Command\ClearCommand;
use Firefly\Cli\Command\HealthCommand;
use Firefly\Cli\Command\Make\MakeComponentCommand;
use Firefly\Cli\Command\Make\MakeConfigPropertiesCommand;
use Firefly\Cli\Command\Make\MakeControllerCommand;
use Firefly\Cli\Command\Make\MakeEntityCommand;
use Firefly\Cli\Command\Make\MakeHandlerCommand;
use Firefly\Cli\Command\Make\MakeListenerCommand;
use Firefly\Cli\Command\Make\MakeRepositoryCommand;
use Firefly\Cli\Command\Make\MakeServiceCommand;
use Firefly\Cli\Command\MetricsCommand;
use Firefly\Cli\Command\RoutesCommand;
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
                ClearCommand::class,
                AboutCommand::class,
                RoutesCommand::class,
                HealthCommand::class,
                MetricsCommand::class,
                MakeControllerCommand::class,
                MakeServiceCommand::class,
                MakeComponentCommand::class,
                MakeHandlerCommand::class,
                MakeListenerCommand::class,
                MakeEntityCommand::class,
                MakeRepositoryCommand::class,
                MakeConfigPropertiesCommand::class,
                // filled task-by-task: Serve/Db.
            ]);
        }
    }
}
