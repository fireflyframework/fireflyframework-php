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
 * later process, so the window is measured from that FIRST OBSERVATION, whichever process served it.
 *
 * `add()` is add-if-absent, so the first process to see the task writes the anchor and every later one
 * reads it back rather than resetting it. It is NOT the driver's atomic add here: Laravel only delegates to
 * the store's own `add()` when a TTL is given, and this anchor deliberately has none (see below), so two
 * processes racing on the very first tick can both write. They write times within the same tick, which
 * moves the window by less than the scheduler's own resolution — not worth a lock, and worth saying out
 * loud so nobody reads atomicity into the call that is not there.
 *
 * HOW LONG "FIRST OBSERVATION" REACHES, stated exactly, because the anchor outlives far more than people
 * assume. It is written with NO EXPIRY on purpose — it is one small value per scheduled task, it must
 * outlive any delay an application configures, and an expiry would silently restart the window in the
 * middle of a deployment's life, which is the outage this class exists to abolish — and nothing else
 * forgets it either. Over the cross-process store an initial delay requires under cron (redis, memcached,
 * database) the anchor therefore survives every later restart AND every later deployment: with no release
 * named, `initialDelay` holds a task back ONCE IN THE LIFE OF THE CACHE KEY. The first deployment waits its
 * ten minutes; every deployment after it reads an anchor from weeks ago, finds the window long elapsed and
 * runs the task on its first tick. That is a defensible default — it is the reading under which a delay is
 * a one-off warm-up for a task that has just been introduced — but it is NOT Spring's, and an operator who
 * wants the window back re-arms it by deleting the key (`firefly:scheduling:initial-delay:*`).
 *
 * `firefly.scheduling.initial-delay.release` is how an application asks for the other reading, the one
 * where every deployment gets its quiet period. There is no application start to measure from here, so the
 * framework cannot derive it: the DEPLOYMENT NAMES ITSELF (a git sha, APP_VERSION, the release directory —
 * whatever the pipeline already has), the anchor records which release armed it, and a process whose
 * release is not the anchor's arms a fresh window for its own. THE VALUE MUST CHANGE ONCE PER DEPLOYMENT
 * AND NEVER WITHIN ONE: a value that varies per process (`uniqid()`, the PID, a boot timestamp) re-anchors
 * every minute and the task NEVER becomes due — the same silent hang a process-local store causes under
 * cron, for the same reason. Empty (the default) keeps the permanent anchor described above, so an
 * application that names nothing behaves exactly as it did before this key existed.
 *
 * The anchor's VALUE is a bare timestamp while no release is named — the shape this gate has always written
 * — and `['release' => …, 'at' => …]` once one is. A bare timestamp read while a release IS named was armed
 * by a deployment that named none, so the first one to name itself takes the key over and arms its own
 * window, once. The re-arm writes with `forever()` rather than `add()`, the key being occupied by
 * definition; two processes of the new release racing on it write times within the same tick, which is the
 * same sub-resolution disagreement the first anchor tolerates.
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
 */
final class InitialDelayGate
{
    public const string ENABLED_KEY = 'firefly.scheduling.initial-delay.enabled';

    public const string STORE_KEY = 'firefly.scheduling.initial-delay.store';

    public const string RELEASE_KEY = 'firefly.scheduling.initial-delay.release';

    public const string ANCHOR_PREFIX = 'firefly:scheduling:initial-delay:';

    /**
     * What this process has already said, keyed by anchor key (plus a suffix for the re-arm note, so a task
     * that is re-armed and later unreadable still reports both). A resident scheduler would otherwise log
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
        $key = $this->anchorKey($descriptor);

        try {
            $anchor = $this->anchor($descriptor, $this->config->string(self::RELEASE_KEY, ''), $now);
        } catch (Throwable $exception) {
            $this->reportOnce($key, "initial delay anchor for {$descriptor->class}::{$descriptor->method} could not be "
                ."reached: {$exception->getMessage()} The delay cannot be honoured, so the task is admitted rather than "
                .'held back in silence.');

            return true;
        }

        if ($anchor === null) {
            $this->reportOnce($key, "initial delay anchor for {$descriptor->class}::{$descriptor->method} is not "
                .'persisting in the configured store — it read back empty immediately after being written. The delay '
                .'cannot be honoured, so the task is admitted rather than held back in silence; point `'
                .self::STORE_KEY.'` at a store that persists.');

            return true;
        }

        return $now >= $anchor + $delaySeconds;
    }

    /**
     * The instant THIS release's window opened, or null when the store cannot read back its own write (the
     * one failure the gate can actually see, reported and failed open by the caller).
     *
     * Three outcomes, in the order they are decided: an absent anchor is armed at `$now` by the add-if-absent
     * below; an anchor this release owns — or any anchor at all while no release is named — is the window,
     * read back untouched however many processes observe it; an anchor armed by SOMEBODY ELSE'S release is
     * taken over, which is the whole point of naming one.
     *
     * The take-over SAYS SO, once per process, because "the task stopped running right after the deploy" is
     * the exact question naming a release invents, and it should be answerable from a log line rather than
     * from this file. Once per process is also the right volume in both deployments: a resident scheduler
     * re-arms once and logs once, while under cron every minute is its own process — so a `release` that
     * wrongly varies per process, which re-anchors forever and never lets the task run, prints the line that
     * names itself every single minute instead of hanging in silence.
     */
    private function anchor(ScheduledDescriptor $descriptor, string $release, float $now): ?float
    {
        $key = $this->anchorKey($descriptor);

        $this->cache->add($key, $this->record($now, $release), null);

        /** @var mixed $stored */
        $stored = $this->cache->get($key);
        $anchoredAt = $this->anchoredAt($stored);

        if ($anchoredAt === null || $release === '' || $this->anchoredFor($stored) === $release) {
            return $anchoredAt;
        }

        $this->cache->forever($key, $this->record($now, $release));
        $this->noteOnce($key.' re-armed', "initial delay for {$descriptor->class}::{$descriptor->method} re-armed for "
            ."release [{$release}]: the anchor in the store was armed by a different release, so this deployment "
            .'waits out its window before the task runs again.');

        return $now;
    }

    private function anchorKey(ScheduledDescriptor $descriptor): string
    {
        return self::ANCHOR_PREFIX.$descriptor->class.'::'.$descriptor->method;
    }

    /**
     * What the anchor is written as: a bare timestamp while no release is named (the shape every anchor in
     * the wild already has, and the one the capstone and any operator inspecting the key sees), the
     * release-stamped record once one is.
     *
     * @return float|array{release: string, at: float}
     */
    private function record(float $now, string $release): float|array
    {
        return $release === '' ? $now : ['release' => $release, 'at' => $now];
    }

    /** The instant a stored anchor was armed, in either shape, or null when it is neither. */
    private function anchoredAt(mixed $stored): ?float
    {
        if (is_numeric($stored)) {
            return (float) $stored;
        }

        if (is_array($stored)) {
            /** @var mixed $at */
            $at = $stored['at'] ?? null;

            return is_numeric($at) ? (float) $at : null;
        }

        return null;
    }

    /** The release a stored anchor was armed for, or null for a bare timestamp, which was armed for none. */
    private function anchoredFor(mixed $stored): ?string
    {
        if (! is_array($stored)) {
            return null;
        }

        /** @var mixed $release */
        $release = $stored['release'] ?? null;

        return is_string($release) ? $release : null;
    }

    private function reportOnce(string $key, string $message): void
    {
        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        $this->logger?->warning($message);
    }

    /** As reportOnce(), for a thing that is working as designed and still worth reading in a log. */
    private function noteOnce(string $key, string $message): void
    {
        if (isset($this->reported[$key])) {
            return;
        }

        $this->reported[$key] = true;
        $this->logger?->info($message);
    }
}
