<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Boot;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Orchestration\Core\Orchestrator;
use Firefly\Orchestration\Manifest\OrchestrationManifest;
use Firefly\Orchestration\Manifest\ScheduledExecution;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Schedule\Cadence;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Wires #[ScheduledSaga] / #[ScheduledTcc] / #[ScheduledWorkflow] onto Laravel's Schedule (spec decision D12):
 * a ScheduledDescriptor cannot carry the input, so this pass attaches its OWN deferred afterResolving hook —
 * the ScheduleWiringPass technique — and reuses scheduling's Cadence for the frequency, so a fixedRate maps to
 * exactly the native cadence a #[Scheduled] method gets. Each run takes the execution's DistributedLock name
 * (one node per tick), starts the definition through the Orchestrator with header `x-firefly-scheduled`, and
 * logs instead of throwing — a failing start must never abort the scheduler.
 */
final class ScheduledExecutionsPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 40;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;
        if (! $container->bound(OrchestrationManifest::class)) {
            return;
        }

        /** @var OrchestrationManifest $manifest */
        $manifest = $container->make(OrchestrationManifest::class);
        $schedules = $manifest->schedules();
        if ($schedules === []) {
            return;
        }

        $container->afterResolving(Schedule::class, static function (Schedule $schedule) use ($container, $schedules): void {
            foreach ($schedules as $index => $scheduled) {
                $event = $schedule->call(static function () use ($container, $scheduled, $index): void {
                    self::start($container, $scheduled, $index);
                });

                Cadence::of(new ScheduledDescriptor(
                    class: Orchestrator::class,
                    method: 'start',
                    cron: $scheduled->cron,
                    fixedRate: $scheduled->fixedRate,
                    fixedDelay: $scheduled->fixedDelay,
                ))->apply($event);

                if ($scheduled->zone !== null) {
                    $event->timezone($scheduled->zone);
                }
                $event->description($scheduled->description !== '' ? $scheduled->description : "orchestration {$scheduled->pattern->value} {$scheduled->name}");
            }
        });
    }

    private static function start(Container $container, ScheduledExecution $scheduled, int $index): void
    {
        $name = $scheduled->lockName($index);
        $lock = null;

        try {
            /** @var DistributedLock $lock */
            $lock = $container->make(DistributedLock::class);
            if (! $lock->tryAcquire($name, 300.0)) {
                $lock = null;

                return; // another node starts it this tick
            }

            /** @var Orchestrator $orchestrator */
            $orchestrator = $container->make(Orchestrator::class);
            $orchestrator->start($scheduled->pattern, $scheduled->name, $scheduled->input, ['x-firefly-scheduled' => 'true']);
        } catch (Throwable $error) {
            $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;
            $message = "Scheduled orchestration {$scheduled->pattern->value} [{$scheduled->name}] failed to start: {$error->getMessage()}";
            if ($logger instanceof LoggerInterface) {
                $logger->error($message, ['exception' => $error]);
            } else {
                error_log($message);
            }
        } finally {
            $lock?->release($name);
        }
    }
}
