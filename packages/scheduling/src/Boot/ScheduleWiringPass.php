<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Boot;

use Closure;
use Firefly\Config\Config;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;
use Firefly\Scheduling\Lock\DistributedLock;
use Firefly\Scheduling\Schedule\Cadence;
use Firefly\Scheduling\Schedule\InitialDelayGate;
use Firefly\Scheduling\Schedule\ScheduledDescriptor;
use Firefly\Scheduling\Schedule\ScheduledManifest;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
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
 *
 * A descriptor's `initialDelay` is applied here too, as the per-tick predicate an initial delay actually is
 * (see InitialDelayGate) — and, when that gate is switched off, REFUSED at boot rather than accepted and
 * ignored, which is what the parameter suffered for two releases.
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

        $config = $context->config;
        $this->refuseUnappliedDelays($manifest, $config);

        $container->afterResolving(Schedule::class, function (Schedule $schedule) use ($manifest, $lock, $container, $config): void {
            // The gate is built HERE, not in run(): the anchor store is only ever read from a tick, and a
            // pass whose whole point is not to touch the Schedule at web boot must not resolve the cache
            // manager there either.
            $gate = new InitialDelayGate($this->cacheStore($container, $config), $config);

            foreach ($manifest->all() as $descriptor) {
                $event = $schedule->call($this->task($container, $lock, $descriptor));
                $this->applyFrequency($event, $descriptor, $gate);
            }
        });
    }

    /**
     * `initialDelay` is APPLIED as a per-tick predicate (see InitialDelayGate) — or, when the gate is
     * switched off, REFUSED at boot. The one thing it must never do again is what it did for two releases:
     * be accepted, compiled into the descriptor and then read by nobody, so a task an author believed would
     * wait ten minutes ran on the first tick and nothing said otherwise. Silence is the failure mode this
     * refusal exists to remove; an application that genuinely wants the old behaviour deletes the parameter.
     */
    private function refuseUnappliedDelays(ScheduledManifest $manifest, Config $config): void
    {
        if ($config->bool(InitialDelayGate::ENABLED_KEY, true)) {
            return;
        }

        foreach ($manifest->all() as $descriptor) {
            if ($descriptor->initialDelay !== null) {
                throw new ConfigurationException(
                    "#[Scheduled(initialDelay: '{$descriptor->initialDelay}')] on {$descriptor->class}::{$descriptor->method} "
                    .'cannot be applied while `'.InitialDelayGate::ENABLED_KEY.'` is false. Turn the gate on, or remove the '
                    .'parameter — it must not be accepted and then ignored.'
                );
            }
        }
    }

    /**
     * The cache store the anchor lives in: `firefly.scheduling.initial-delay.store` when it names one, the
     * application's default store otherwise. A container with no cache manager at all (a bare unit-test
     * container) gets an array repository, so the pass never fails over a feature no task uses.
     */
    private function cacheStore(Container $container, Config $config): Repository
    {
        if (! $container->bound(CacheFactory::class)) {
            return new CacheRepository(new ArrayStore);
        }

        /** @var CacheFactory $cache */
        $cache = $container->make(CacheFactory::class);
        $store = $config->string(InitialDelayGate::STORE_KEY, '');

        return $store === '' ? $cache->store() : $cache->store($store);
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

    private function applyFrequency(Event $event, ScheduledDescriptor $descriptor, InitialDelayGate $gate): void
    {
        // The trigger-to-cadence table lives in Cadence so `firefly:schedule` prints the same answer this
        // pass wires; see that class for the rounding rule and for the sub-minute half of the table.
        Cadence::of($descriptor)->apply($event);

        if ($descriptor->zone !== null) {
            $event->timezone($descriptor->zone);
        }

        // An initial delay is not a cadence — Laravel's frequency DSL cannot express one — so it rides on
        // the per-tick predicate Laravel DOES have. The cadence above still decides which minutes are
        // candidates; this decides whether the window has opened yet.
        if ($descriptor->initialDelay !== null) {
            $event->when(static fn (): bool => $gate->isDue($descriptor));
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
