<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Schedule;

use Firefly\Config\Config;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `#[Scheduled(initialDelay: '10m')]` finally does something.
 *
 * The parameter has been captured through to the compiled descriptor since M7 and read by nothing, because
 * Laravel's frequency DSL has no way to say "run once after an initial delay, then resume the cadence". It
 * does, however, have `Event::when()` — a per-tick predicate — and an initial delay IS a predicate: refuse
 * every tick until the window has passed.
 *
 * THE HARD PART IS THE ANCHOR, and it is why this is a class rather than three lines in the wiring pass.
 * Spring measures `initialDelay` from application start, which works because the application is a process
 * that stays up. The baseline deployment here is cron-driven `schedule:run`: a FRESH PHP process every
 * minute, whose "application start" is always a second ago, so a window measured from boot would never
 * elapse and the task would never run — a silent hang that looks exactly like a task nobody scheduled. The
 * anchor is therefore written to the cache the first time the gate sees the task and read back by every
 * later process, so the window is measured from the FIRST tick after deployment, whichever process served
 * it.
 *
 * `add()` is add-if-absent, so the first process to see the task writes the anchor and every later one
 * reads it back rather than resetting it. It is NOT the driver's atomic add here: Laravel only delegates to
 * the store's own `add()` when a TTL is given, and this anchor deliberately has none (see below), so two
 * processes racing on the very first tick can both write. They write times within the same tick, which
 * moves the window by less than the scheduler's own resolution — not worth a lock, and worth saying out
 * loud so nobody reads atomicity into the call that is not there.
 *
 * WHAT A STORE THAT CANNOT HOLD THE ANCHOR ACTUALLY DOES, stated exactly, because the obvious guess ("the
 * delay elapses immediately") is wrong in the direction that hurts. The window is measured from the first
 * tick whose process can read the anchor back, so:
 *   - a RESIDENT scheduler (`schedule:work`, Octane) over `array` honours the delay, measured from the first
 *     tick that process served — the store outlives every tick the window needs;
 *   - a CRON-DRIVEN `schedule:run` over `array` re-anchors in a fresh process every minute, so the anchor is
 *     always `$now` and the window NEVER elapses. The task does not run early; it never runs at all. Nothing
 *     is visible from inside a tick (the write succeeds, the read succeeds), so it is said one level up:
 *     ScheduleWiringPass warns as the Schedule is built when the resolved anchor store cannot outlive the
 *     process and some task carries a delay. `initialDelay` needs a store SHARED ACROSS PROCESSES (redis,
 *     memcached, database) wherever the scheduler is cron-driven;
 *   - the `null` driver, or a cache that is simply down, stores nothing and hands back nothing. THAT the
 *     gate can see — a store which cannot read its own write cannot gate anything — and it admits the tick
 *     rather than refusing it, reporting once per process through the logger. Fail OPEN is the only honest
 *     choice here: refusing would turn a cache outage into a task that stopped firing with nothing logged
 *     and nothing thrown, which is the silent hang this whole class exists to abolish. A task that runs
 *     without its delay is a visible wrong; a task that never runs is an invisible one.
 * `array` is warned about rather than refused, because refusing would make it unusable in exactly the suites
 * that need to assert on scheduling.
 *
 * The anchor is written with no expiry ON PURPOSE: it is one small float per scheduled task, it must
 * outlive any delay an application configures, and losing it silently restarts the window.
 */
final class InitialDelayGate
{
    public const string ENABLED_KEY = 'firefly.scheduling.initial-delay.enabled';

    public const string STORE_KEY = 'firefly.scheduling.initial-delay.store';

    public const string ANCHOR_PREFIX = 'firefly:scheduling:initial-delay:';

    /**
     * Anchors already reported as unreadable, keyed by anchor key. A resident scheduler would otherwise log
     * the same line every tick for the life of the process; one line per task per process is the report.
     *
     * @var array<string, true>
     */
    private array $reported = [];

    public function __construct(
        private readonly Repository $cache,
        private readonly Config $config,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Whether this tick may run. A descriptor with no `initialDelay`, and every descriptor at all while the
     * feature is switched off, is admitted without so much as touching the cache — an inert gate must not
     * leave anchors behind for a later boot to measure from.
     *
     * `$delaySeconds` arrives ALREADY PARSED, from ScheduleWiringPass, which resolves it once as the
     * Schedule is built and refuses an unparseable one at boot. Nothing here may parse a duration: this runs
     * inside `Event::filtersPass()`, which Laravel calls outside the try/catch that contains a failing task,
     * so a throw from this method does not skip one task — it aborts the entire `schedule:run` for that
     * minute, every other due task with it.
     */
    public function isDue(ScheduledDescriptor $descriptor, float $delaySeconds, ?float $now = null): bool
    {
        if ($descriptor->initialDelay === null || ! $this->config->bool(self::ENABLED_KEY, true)) {
            return true;
        }

        $now ??= microtime(true);
        $key = self::ANCHOR_PREFIX.$descriptor->class.'::'.$descriptor->method;

        try {
            $this->cache->add($key, $now, null);

            /** @var mixed $anchor */
            $anchor = $this->cache->get($key);
        } catch (Throwable $exception) {
            $this->reportOnce($key, "initial delay anchor for {$descriptor->class}::{$descriptor->method} could not be "
                ."reached: {$exception->getMessage()} The delay cannot be honoured, so the task is admitted rather than "
                .'held back in silence.');

            return true;
        }

        if (! is_numeric($anchor)) {
            $this->reportOnce($key, "initial delay anchor for {$descriptor->class}::{$descriptor->method} is not "
                .'persisting in the configured store — it read back empty immediately after being written. The delay '
                .'cannot be honoured, so the task is admitted rather than held back in silence; point `'
                .self::STORE_KEY.'` at a store that persists.');

            return true;
        }

        return $now >= (float) $anchor + $delaySeconds;
    }

    private function reportOnce(string $key, string $message): void
    {
        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        $this->logger?->warning($message);
    }
}
