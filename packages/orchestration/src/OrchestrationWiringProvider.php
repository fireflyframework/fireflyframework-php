<?php

declare(strict_types=1);

namespace Firefly\Orchestration;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Orchestration\Boot\EventGatewayWiringPass;
use Firefly\Orchestration\Boot\OrchestrationRegistryPass;
use Firefly\Orchestration\Boot\OrchestrationRouteRegistrar;
use Firefly\Orchestration\Boot\OrchestrationSchedulePass;
use Firefly\Orchestration\Boot\ScheduledExecutionsPass;
use Firefly\Orchestration\Console\DeadLetterCommand;
use Firefly\Orchestration\Console\ListExecutionsCommand;
use Firefly\Orchestration\Console\RetryDeadLetterCommand;
use Firefly\Orchestration\Console\ShowExecutionCommand;
use Firefly\Orchestration\Console\SignalWorkflowCommand;
use Firefly\Orchestration\Console\TickCommand;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\Scanner\OrchestrationScanner;
use Illuminate\Contracts\Container\Container;

/**
 * The boot-pass half of firefly/orchestration. It cannot ride on OrchestrationServiceProvider (an
 * AutoConfiguration records candidacy only), so — exactly like SchedulingWiringProvider and
 * FeatureFlagsWiringProvider — this plain FireflyServiceProvider:
 *
 *  - binds the OrchestrationManifest behind a bound() guard: the compiled `orchestration.php` first, then an
 *    in-process scan of `firefly.scan.paths`, then an empty manifest (the AppScan convention), lazily, so a
 *    cached application that has firefly/cli pays nothing;
 *  - contributes the registry, gateway, schedule (the tick and the recovery sweep) and scheduled-execution
 *    (#[ScheduledSaga] / #[ScheduledTcc] / #[ScheduledWorkflow]) passes, and the REST route registrar that
 *    mounts nothing unless firefly.orchestration.rest.enabled;
 *  - registers the firefly:orchestration:* commands (list, show, signal, dlq, dlq:retry, tick) in a console;
 *  - publishes the migration (`--tag=firefly-orchestration-migrations`) and loads it while persistence or
 *    the DLQ is `database`.
 */
final class OrchestrationWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATION = '2026_10_12_000000_create_firefly_orchestration_tables.php';

    public function register(): void
    {
        if (! $this->app->bound(OrchestrationManifest::class)) {
            $this->app->singleton(OrchestrationManifest::class, static function (Container $app): OrchestrationManifest {
                if (($file = AppScan::cachedFile($app, AppScan::ORCHESTRATION)) !== null) {
                    return OrchestrationManifest::load($file);
                }

                $paths = AppScan::paths($app);

                return $paths === [] ? OrchestrationManifest::empty() : (new OrchestrationScanner)->scan($paths);
            });
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new OrchestrationRegistryPass, new EventGatewayWiringPass, new OrchestrationSchedulePass, new ScheduledExecutionsPass, new OrchestrationRouteRegistrar];
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListExecutionsCommand::class,
                ShowExecutionCommand::class,
                SignalWorkflowCommand::class,
                DeadLetterCommand::class,
                RetryDeadLetterCommand::class,
                TickCommand::class,
            ]);
        }

        $migrations = dirname(__DIR__).'/database/migrations';
        $this->publishes([$migrations.'/'.self::MIGRATION => $this->app->databasePath('migrations/'.self::MIGRATION)], 'firefly-orchestration-migrations');

        $config = new Config($this->app->make('config'));
        if ($config->string('firefly.orchestration.persistence.provider', 'memory') === 'database'
            || $config->string('firefly.orchestration.dlq.store', 'memory') === 'database') {
            $this->loadMigrationsFrom($migrations);
        }
    }
}
