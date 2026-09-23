<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Schedule;

use Firefly\Config\Config;
use Firefly\Resilience\Duration;
use Illuminate\Contracts\Cache\Repository;

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
 * A cache store that does not persist between requests (`array`, `null`) degrades to "the delay elapses
 * immediately, once per process", which is correct under a resident scheduler and harmless in tests. It is
 * called out in the module doc rather than refused, because refusing would make `array` unusable in exactly
 * the suites that need to assert on scheduling.
 *
 * The anchor is written with no expiry ON PURPOSE: it is one small float per scheduled task, it must
 * outlive any delay an application configures, and losing it silently restarts the window.
 */
final class InitialDelayGate
{
    public const string ENABLED_KEY = 'firefly.scheduling.initial-delay.enabled';

    public const string STORE_KEY = 'firefly.scheduling.initial-delay.store';

    public const string ANCHOR_PREFIX = 'firefly:scheduling:initial-delay:';

    public function __construct(
        private readonly Repository $cache,
        private readonly Config $config,
    ) {}

    /**
     * Whether this tick may run. A descriptor with no `initialDelay`, and every descriptor at all while the
     * feature is switched off, is admitted without so much as touching the cache — an inert gate must not
     * leave anchors behind for a later boot to measure from.
     */
    public function isDue(ScheduledDescriptor $descriptor, ?float $now = null): bool
    {
        if ($descriptor->initialDelay === null || ! $this->config->bool(self::ENABLED_KEY, true)) {
            return true;
        }

        $now ??= microtime(true);
        $key = self::ANCHOR_PREFIX.$descriptor->class.'::'.$descriptor->method;

        $this->cache->add($key, $now, null);

        /** @var mixed $anchor */
        $anchor = $this->cache->get($key);
        $anchor = is_numeric($anchor) ? (float) $anchor : $now;

        return $now >= $anchor + Duration::parse($descriptor->initialDelay);
    }
}
