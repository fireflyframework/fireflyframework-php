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
use Illuminate\Cache\NullStore;
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
 * ignored, which is what the parameter suffered for two releases. The same boot-time pass parses every
 * delay string, because the tick predicate is the one place in this file a throw is NOT contained: Laravel
 * calls `filtersPass()` outside the try/catch that wraps `$event->run()`, so a throw there aborts the whole
 * minute's run rather than one task.
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
        $this->validateDelays($manifest, $config);

        $container->afterResolving(Schedule::class, function (Schedule $schedule) use ($manifest, $lock, $container, $config): void {
            // The anchor store is resolved HERE, not in run(): it is only ever read from a tick, and a pass
            // whose whole point is not to touch the Schedule at web boot must not resolve the cache manager
            // there either.
            $store = $this->cacheStore($container, $config);
            $gate = new InitialDelayGate($store, $config, $this->logger($container));
            $this->warnOnVolatileAnchorStore($container, $store, $manifest);

            foreach ($manifest->all() as $descriptor) {
                $event = $schedule->call($this->task($container, $lock, $descriptor));
                $this->applyFrequency($event, $descriptor);
                $this->applyInitialDelay($event, $descriptor, $gate, $container);
            }
        });
    }

    /**
     * `initialDelay` is APPLIED as a per-tick predicate (see InitialDelayGate) — or, when the gate is
     * switched off, REFUSED at boot. The one thing it must never do again is what it did for two releases:
     * be accepted, compiled into the descriptor and then read by nobody, so a task an author believed would
     * wait ten minutes ran on the first tick and nothing said otherwise. Silence is the failure mode this
     * refusal exists to remove; an application that genuinely wants the old behaviour deletes the parameter.
     *
     * Every delay string is also PARSED here, for its refusal rather than its value. Neither the attribute
     * nor the scanner validates it, and `Duration::parse()` throws — so left to the predicate, one typo
     * ('ten minutes') would throw from inside `Event::filtersPass()`, which ScheduleRunCommand calls outside
     * the try/catch that contains a failing task: the whole minute's run would abort, every other due task
     * skipped, for a string nobody had ever looked at. Boot is where a configuration mistake belongs.
     */
    private function validateDelays(ScheduledManifest $manifest, Config $config): void
    {
        $enabled = $config->bool(InitialDelayGate::ENABLED_KEY, true);

        foreach ($manifest->all() as $descriptor) {
            if ($descriptor->initialDelay === null) {
                continue;
            }

            if (! $enabled) {
                throw new ConfigurationException(
                    "#[Scheduled(initialDelay: '{$descriptor->initialDelay}')] on {$descriptor->class}::{$descriptor->method} "
                    .'cannot be applied while `'.InitialDelayGate::ENABLED_KEY.'` is false. Turn the gate on, or remove the '
                    .'parameter — it must not be accepted and then ignored.'
                );
            }

            try {
                Duration::parse($descriptor->initialDelay);
            } catch (ConfigurationException $exception) {
                throw new ConfigurationException(
                    "#[Scheduled(initialDelay: '{$descriptor->initialDelay}')] on {$descriptor->class}::{$descriptor->method} "
                    ."is not a duration this framework can parse. {$exception->getMessage()}",
                    previous: $exception,
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

    /**
     * An initial delay needs a cache store that OUTLIVES THE PROCESS, and the failure to have one is
     * invisible from inside a tick. Under cron-driven `schedule:run` every minute is a fresh process: with
     * `array`, each one writes its own anchor, reads back the value it just wrote, finds the window not
     * elapsed and skips — forever, with nothing logged and nothing thrown. Both the write and the read
     * SUCCEED, so InitialDelayGate has nothing to complain about; only this level knows which store was
     * resolved. Hence one warning, as the Schedule is built, and only when a task actually carries a delay.
     *
     * It is a warning and not a refusal because the same store is correct under a resident scheduler
     * (`schedule:work`, Octane), which keeps one process, and because `array` is what every test suite in
     * the world runs on — refusing would make scheduling untestable to fix a deployment mistake.
     */
    private function warnOnVolatileAnchorStore(Container $container, Repository $store, ScheduledManifest $manifest): void
    {
        $delayed = array_filter(
            $manifest->all(),
            static fn (ScheduledDescriptor $descriptor): bool => $descriptor->initialDelay !== null,
        );

        if ($delayed === []) {
            return;
        }

        $driver = $store instanceof CacheRepository ? $store->getStore() : null;

        if (! $driver instanceof ArrayStore && ! $driver instanceof NullStore) {
            return;
        }

        $this->warn($container, sprintf(
            'Scheduled initial delays are anchored in a [%s] cache store, which does not survive the process. A '
            .'resident scheduler (schedule:work, Octane) honours the delay; a cron-driven schedule:run re-anchors '
            .'every minute and the delayed task NEVER becomes due. Point `%s` at a store shared across processes '
            .'(redis, memcached, database). Tasks affected: %s.',
            $driver instanceof NullStore ? 'null' : 'array',
            InitialDelayGate::STORE_KEY,
            implode(', ', array_map(
                static fn (ScheduledDescriptor $descriptor): string => $descriptor->class.'::'.$descriptor->method,
                $delayed,
            )),
        ));
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

    /**
     * An initial delay is not a cadence — Laravel's frequency DSL cannot express one — so it rides on the
     * per-tick predicate Laravel DOES have. The cadence decides which minutes are candidates; this decides
     * whether the window has opened yet.
     *
     * The duration is parsed HERE, once per process as the Schedule is built, and never inside the
     * predicate — validateDelays() has already proved at boot that it parses. The predicate body is wrapped
     * as well, and the belt matters more than it looks: `ScheduleRunCommand::handle()` calls
     * `$event->filtersPass()` OUTSIDE the try/catch that wraps `$event->run()`, so an escaping throw skips
     * not this task but every task due that minute. The gate contains its own store failures; this contains
     * everything else it might reach (a `Config` value of the wrong shape, say) and fails OPEN, because one
     * task running ungated is a smaller wrong than a scheduler that stopped.
     */
    private function applyInitialDelay(Event $event, ScheduledDescriptor $descriptor, InitialDelayGate $gate, Container $container): void
    {
        if ($descriptor->initialDelay === null) {
            return;
        }

        $delay = Duration::parse($descriptor->initialDelay);

        $event->when(function () use ($gate, $descriptor, $delay, $container): bool {
            try {
                return $gate->isDue($descriptor, $delay);
            } catch (Throwable $exception) {
                $this->report($container, $descriptor, $exception);

                return true;
            }
        });
    }

    private function lockTtl(ScheduledDescriptor $descriptor): float
    {
        return $descriptor->lockTtl !== null ? Duration::parse($descriptor->lockTtl) : 30.0;
    }

    private function report(Container $container, ScheduledDescriptor $descriptor, Throwable $exception): void
    {
        $message = "Scheduled task {$descriptor->class}::{$descriptor->method} failed: {$exception->getMessage()}";

        $logger = $this->logger($container);
        if ($logger instanceof LoggerInterface) {
            $logger->error($message, ['exception' => $exception]);

            return;
        }

        error_log($message);
    }

    private function warn(Container $container, string $message): void
    {
        $logger = $this->logger($container);
        if ($logger instanceof LoggerInterface) {
            $logger->warning($message);

            return;
        }

        error_log($message);
    }

    private function logger(Container $container): ?LoggerInterface
    {
        $logger = $container->bound(LoggerInterface::class) ? $container->make(LoggerInterface::class) : null;

        return $logger instanceof LoggerInterface ? $logger : null;
    }
}
