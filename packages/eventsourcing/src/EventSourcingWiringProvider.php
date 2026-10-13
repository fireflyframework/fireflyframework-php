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
 * firefly:eventsourcing:projections* commands in a console, publishes the tables migration
 * (`--tag=firefly-eventsourcing-migrations`) and the orchestration read-model migration
 * (`--tag=firefly-eventsourcing-orchestration-migrations`), and loads the first while any eventsourcing store is
 * `database`, the second while orchestration persistence is `event-sourced` over the database store.
 */
final class EventSourcingWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';

    public const string TABLES_MIGRATION = '2026_10_13_000000_create_firefly_eventsourcing_tables.php';

    public const string ORCHESTRATION_MIGRATION = '2026_10_13_000100_create_firefly_eventsourcing_orchestration_read_model.php';

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

        $tables = self::MIGRATIONS.'/'.self::TABLES_MIGRATION;
        $readModel = self::MIGRATIONS.'/'.self::ORCHESTRATION_MIGRATION;
        $this->publishes([$tables => $this->app->databasePath('migrations/'.self::TABLES_MIGRATION)], 'firefly-eventsourcing-migrations');
        $this->publishes([$readModel => $this->app->databasePath('migrations/'.self::ORCHESTRATION_MIGRATION)], 'firefly-eventsourcing-orchestration-migrations');

        $config = new Config($this->app->make('config'));
        $settings = EventSourcingSettings::fromConfig($config);
        if ($settings->usesDatabase()) {
            $this->loadMigrationsFrom($tables);
        }
        if ($settings->storeProvider === 'database' && $config->string('firefly.orchestration.persistence.provider', 'memory') === 'event-sourced') {
            $this->loadMigrationsFrom($readModel);
        }
    }
}
