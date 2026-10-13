<?php

declare(strict_types=1);

namespace Firefly\EventSourcing;

use Firefly\Config\Config;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\EventSourcing\Console\ListProjectionsCommand;
use Firefly\EventSourcing\Console\RebuildProjectionCommand;
use Firefly\EventSourcing\Console\RunProjectionsCommand;
use Firefly\EventSourcing\Manifest\EventSourcingManifest;
use Firefly\EventSourcing\Scanner\EventSourcingScanner;
use Illuminate\Contracts\Container\Container;

/**
 * The boot half of firefly/eventsourcing, a plain FireflyServiceProvider because an AutoConfiguration records
 * candidacy only (the OrchestrationWiringProvider shape). It binds the EventSourcingManifest behind a bound() guard:
 * the compiled `eventsourcing.php` first, then an in-process scan of `firefly.scan.paths`, then an empty manifest
 * (the AppScan convention), lazily, so a cached application pays nothing. It registers the
 * firefly:eventsourcing:projections* commands in a console, publishes the migrations
 * (`--tag=firefly-eventsourcing-migrations`) and loads them while any eventsourcing store is `database`.
 */
final class EventSourcingWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public function register(): void
    {
        if (! $this->app->bound(EventSourcingManifest::class)) {
            $this->app->singleton(EventSourcingManifest::class, static function (Container $app): EventSourcingManifest {
                if (($file = AppScan::cachedFile($app, AppScan::EVENTSOURCING)) !== null) {
                    return EventSourcingManifest::load($file);
                }

                $paths = AppScan::paths($app);

                return $paths === [] ? EventSourcingManifest::empty() : (new EventSourcingScanner)->scan($paths);
            });
        }

        parent::register();
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ListProjectionsCommand::class, RunProjectionsCommand::class, RebuildProjectionCommand::class]);
        }

        $this->publishes([self::MIGRATIONS => $this->app->databasePath('migrations')], 'firefly-eventsourcing-migrations');

        if (EventSourcingSettings::fromConfig(new Config($this->app->make('config')))->usesDatabase()) {
            $this->loadMigrationsFrom(self::MIGRATIONS);
        }
    }
}
