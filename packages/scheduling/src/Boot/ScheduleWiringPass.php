<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Boot;

use Closure;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Resilience\Duration;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Schedule\Cadence;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Registers the app's #[Scheduled] tasks onto Laravel's native Schedule — but LAZILY. Laravel resolves the
 * Schedule only when `schedule:run` builds it, NEVER during a web-request boot, so this pass (at phase
 * WiringPasses/1000, the instance stage) attaches a DEFERRED afterResolving(Schedule::class, …) hook rather than
 * resolving the Schedule eagerly. When the Schedule is finally resolved, the hook maps each descriptor to a
 * $schedule->call($closure) at the right frequency; the closure resolves the target bean and, when the
 * descriptor carries a lock, guards the body with OUR DistributedLock (so the configured backend — including the
 * Postgres advisory adapter — governs, not Laravel's cache-only onOneServer). A thrown tick is logged and
 * swallowed: a failing task must never abort the scheduler (pyfly _invoke parity).
 */
final class ScheduleWiringPass implements BootPass
{
    public function phase(): BootPhase
    {
        return BootPhase::WiringPasses;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $container = $context->container;

        /** @var ScheduledManifest $manifest */
        $manifest = $container->make(ScheduledManifest::class);
        /** @var DistributedLock $lock */
        $lock = $container->make(DistributedLock::class);

        $container->afterResolving(Schedule::class, function (Schedule $schedule) use ($manifest, $lock, $container): void {
            foreach ($manifest->all() as $descriptor) {
                $event = $schedule->call($this->task($container, $lock, $descriptor));
                $this->applyFrequency($event, $descriptor);
            }
        });
    }

    private function task(Container $container, DistributedLock $lock, ScheduledDescriptor $descriptor): Closure
    {
        return function () use ($container, $lock, $descriptor): void {
            $lockName = $descriptor->lockName;
            if ($lockName !== null && ! $lock->tryAcquire($lockName, $this->lockTtl($descriptor))) {
                return; // held elsewhere this tick — skip
            }

            try {
                $bean = $container->make($descriptor->class);
                if (is_object($bean) && method_exists($bean, $descriptor->method)) {
                    $method = $descriptor->method;
                    $bean->{$method}();
                }
            } catch (Throwable $exception) {
                $this->report($container, $descriptor, $exception);
            } finally {
                if ($lockName !== null) {
                    $lock->release($lockName);
                }
            }
        };
    }

    private function applyFrequency(Event $event, ScheduledDescriptor $descriptor): void
    {
        // The trigger-to-cadence table lives in Cadence so `firefly:schedule` prints the same answer this
        // pass wires; see that class for the rounding rule and for the sub-minute half of the table.
        Cadence::of($descriptor)->apply($event);

        if ($descriptor->zone !== null) {
            $event->timezone($descriptor->zone);
        }
    }

    private function lockTtl(ScheduledDescriptor $descriptor): float
    {
        return $descriptor->lockTtl !== null ? Duration::parse($descriptor->lockTtl) : 30.0;
    }

    private function report(Container $container, ScheduledDescriptor $descriptor, Throwable $exception): void
    {
        $message = "Scheduled task {$descriptor->class}::{$descriptor->method} failed: {$exception->getMessage()}";

        $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;
        if ($logger instanceof LoggerInterface) {
            $logger->error($message, ['exception' => $exception]);

            return;
        }

        error_log($message);
    }
}
