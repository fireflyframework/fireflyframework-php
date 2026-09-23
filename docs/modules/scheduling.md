# Scheduling

`firefly/scheduling` layers a Spring/pyfly-style `#[Scheduled]` attribute and a distributed-lock guard onto
Laravel's own task scheduler, so a scheduled method runs on Laravel's native `Schedule` — no bespoke
scheduler loop of its own — while still supporting the "runs on at most one node" guarantee a multi-instance
deployment needs.

## The `DistributedLock` port

`Firefly\Scheduling\Lock\DistributedLock` is a ShedLock analog:

<!-- source: packages/scheduling/src/Lock/DistributedLock.php -->
```php
interface DistributedLock
{
    /** Try to acquire $name for up to $ttlSeconds; false if it is already held elsewhere (never blocks). */
    public function tryAcquire(string $name, float $ttlSeconds): bool;

    public function release(string $name): void;
}
```

`tryAcquire()` never blocks — it either wins the lock immediately or reports it is held elsewhere, so a
scheduler tick that loses the race skips its work for that tick rather than waiting.

### Backends

The active backend is selected by `firefly.scheduling.lock.provider`, wired by `SchedulingAutoConfiguration`
(`#[Order(1000)]`, `#[ConditionalOnMissingBean(DistributedLock::class)]`, so an app-bound `DistributedLock`
always wins):

| `firefly.scheduling.lock.provider` | Class | Package | Notes |
|---|---|---|---|
| *(unset)* / `none` | `NoneLock` | `firefly/scheduling` | **The default.** Every `tryAcquire()` succeeds and `release()` is a no-op — correct for a single-instance deployment (the only "node" always wins) and for a bare skeleton with no lock infrastructure, but provides **no real mutual exclusion** across nodes. |
| `cache` | `CacheLock` | `firefly/scheduling` | Wraps Laravel's atomic `Cache::lock()`. Works over any lock-capable driver (`database`, `redis`, `memcached`, and `array` for tests). The `null` cache driver grants **no-op** locks (every acquire "succeeds" with no real exclusion, same as `NoneLock`); `array` is **per-process only** — it does not coordinate across separate PHP-FPM workers or hosts. Production multi-node deployments need `database` or `redis`. |
| `postgres` | `PgAdvisoryLock` | `firefly/scheduling-postgres` | Session-scoped Postgres advisory lock (see below). Requires installing the separate adapter package. |

`CacheLock::tryAcquire()` throws a `ConfigurationException` up front if the configured cache store isn't a
`LockProvider` at all (rather than silently degrading), naming the driver requirement explicitly.

### `PgAdvisoryLock` (`firefly/scheduling-postgres`)

`Firefly\Scheduling\Postgres\PgAdvisoryLock` backs the lock with Postgres **session-level** advisory locks
(`pg_try_advisory_lock` / `pg_advisory_unlock`), keyed by a deterministic name→bigint hash
(`PgAdvisoryLock::key()`, the top 60 bits of a SHA-256 digest folded into the signed `bigint` range). It
opts in only behind `firefly.scheduling.lock.provider=postgres`, gated by
`PgAdvisoryLockAutoConfiguration`'s `#[ConditionalOnProperty(name: 'firefly.scheduling.lock.provider',
havingValue: 'postgres')]` — installing the package with the property unset is otherwise completely inert
(`#[Order(1000)]` matches `SchedulingAutoConfiguration`'s own default bean, which backs off via
`#[ConditionalOnMissingBean]` once this one binds `DistributedLock`).

## `#[Scheduled]`

<!-- source: packages/scheduling/src/Attributes/Scheduled.php -->
```php
#[Attribute(Attribute::TARGET_METHOD)]
final class Scheduled
{
    public function __construct(
        public ?string $cron = null,
        public ?string $fixedRate = null,
        public ?string $fixedDelay = null,
        public ?string $initialDelay = null,
        public ?string $zone = null,
        public string|bool|null $lock = null,
        public ?string $lockTtl = null,
    ) {
        $triggers = array_filter(
            [$cron, $fixedRate, $fixedDelay],
            static fn (?string $trigger): bool => $trigger !== null,
        );

        if (count($triggers) !== 1) {
            throw new InvalidArgumentException(
                '#[Scheduled] requires exactly one of cron, fixedRate or fixedDelay to be set.',
            );
        }
    }
}
```

Exactly one of `cron`, `fixedRate`, or `fixedDelay` must be set (the attribute's constructor throws
`InvalidArgumentException` otherwise):

- **`cron`** — a Laravel/crontab expression, applied to the scheduled `Event` verbatim (`$event->cron(...)`).
- **`fixedRate`** / **`fixedDelay`** — a `Duration`-parsed string (`'250ms'`, `'30s'`, `'5m'`, `'1h'`, or a
  bare number of seconds) mapped to the *nearest* native Laravel frequency method — see
  [Known-latent](#known-latent).
- **`lock`** — `true` shares a lock named `"Class::method"` (derived from the annotated method); a string is
  an explicit shared lock name (so several methods can share one lock); `null`/`false` (the default) runs
  unlocked, with no `DistributedLock` guard at all.
- **`lockTtl`** — a `Duration`-parsed string bounding how long the lock may be held; defaults to `30.0`
  seconds when the trigger is locked and no `lockTtl` is given.

<!-- illustrative: an application's own bean with two scheduled methods -->
```php
final class Reconciliation
{
    #[Scheduled(cron: '0 3 * * *', lock: true)]
    public function nightlyReconcile(): void
    {
        // ...
    }

    #[Scheduled(fixedRate: '5m', lock: 'billing-sweep', lockTtl: '2m')]
    public function billingSweep(): void
    {
        // ...
    }
}
```

- **`zone`** — an IANA timezone name (e.g. `'America/New_York'`), applied to the registered `Event` via
  Laravel's own `$event->timezone(...)` (`Illuminate\Console\Scheduling\ManagesFrequencies::timezone()`), so
  the cron/frequency expression is evaluated in that timezone instead of the scheduler's default.
- **`initialDelay`** — a `Duration`-parsed string holding the task back before its first run, then leaving
  the cadence alone. It is applied; see [Initial delay](#initial-delay) for the anchor it is measured from
  and the cache store a cron-driven scheduler needs to honour it.

## Discovery: `ScheduledScanner` → `ScheduledManifest`

`Firefly\Scheduling\Scanner\ScheduledScanner` is the package's **sole reflection site** (a grep-enforced
invariant): it walks the app's PSR-4 roots, reflects every public method carrying `#[Scheduled]`, and emits
one pure-array `ScheduledDescriptor` per annotation — never running in production request paths.
`ScheduledManifestCompiler` `var_export`s the descriptor list to a plain, `require`-able PHP array literal
(mirroring the M6 `RouteManifestCompiler` idiom), and `ScheduledManifest::load()` loads it back reflection-free.
`SchedulingWiringProvider` binds a default empty `ScheduledManifest` behind a `bound()` guard, so a bare
skeleton with no compiled manifest still boots cleanly; an app (or `firefly:cache`) that binds its own
compiled manifest wins.

## `ScheduleWiringPass`: lazy registration onto Laravel's `Schedule`

`Firefly\Scheduling\Boot\ScheduleWiringPass` runs at boot phase `WiringPasses`, order `0`. It must register
tasks **lazily**: Laravel only resolves `Schedule` when `schedule:run` actually builds it, never during a
web-request boot, so the pass never resolves `Schedule` eagerly — instead it attaches a deferred
`$container->afterResolving(Schedule::class, function (Schedule $schedule) { ... })` hook. When (and only
when) `Schedule` is eventually resolved, the hook walks every `ScheduledManifest` descriptor and:

1. Calls `$schedule->call($closure)` with a closure that resolves the target bean from the container and
   invokes the annotated method — wrapped in the lock guard described below.
2. Maps the descriptor's trigger onto the returned `Event` through `Firefly\Scheduling\Schedule\Cadence`:
   `cron` applies verbatim; `fixedRate`/`fixedDelay` parse to seconds and bucket onto the nearest cadence
   **not shorter than the rate** — below a minute, Laravel's repeat-seconds cadences `everySecond()`,
   `everyTwoSeconds()`, `everyFiveSeconds()`, `everyTenSeconds()`, `everyFifteenSeconds()`,
   `everyTwentySeconds()`, `everyThirtySeconds()` (honoured by `schedule:work`, which re-runs the event inside
   the minute); at or above it `everyMinute()`, `everyFiveMinutes()`, `everyTenMinutes()`,
   `everyFifteenMinutes()`, `everyThirtyMinutes()`, `hourly()`, `daily()`, or `weekly()`. Rounding is always
   *up*: `'7s'` runs every 10 seconds, `'45s'` every minute, `'7m'` every ten. `Cadence::describe()` is what
   `php artisan firefly:schedule` prints beside each task, so the listing and the wiring cannot disagree.

### The lock guard (at-most-one-node)

The task closure itself is where the distributed lock actually gates execution:

<!-- source: packages/scheduling/src/Boot/ScheduleWiringPass.php -->
```php
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
```

The `report()` call logs and never rethrows, so one task's failure never stops the tick.

An unlocked task (`lock` unset) always runs — every node that ticks runs it. A locked task's body runs on
whichever node's `tryAcquire()` wins the race for that tick; every other node silently skips (`return`, no
exception, no log) and the lock is released in `finally` once the winner's body completes. Note this guards
**our** `DistributedLock` — including the Postgres advisory adapter — rather than Laravel's own
cache-only `Event::onOneServer()`, so the configured backend (not Laravel's) governs multi-node exclusion.

A thrown exception from the task body is **logged and swallowed**, never propagated — a failing scheduled
task must never abort the rest of the scheduler run (pyfly `_invoke` parity). It logs via the bound
`Psr\Log\LoggerInterface` when one is available, falling back to `error_log()` otherwise.

## Initial delay

Laravel's frequency DSL cannot express "run once after a delay, then resume the cadence". It does have
`Event::when()` — a per-tick predicate — and an initial delay **is** a predicate: refuse every tick until the
window has passed. `Firefly\Scheduling\Schedule\InitialDelayGate` is that predicate.

**The anchor is in the cache, not in process memory.** Spring measures `initialDelay` from application start,
which works because the application is a process that stays up. The baseline deployment here is a cron-driven
`schedule:run`: a fresh PHP process every minute, whose "application start" is always a second ago, so a
window measured from boot would never elapse and the task would never run — a silent hang that looks exactly
like a task nobody scheduled. The gate writes the anchor to the cache
(`firefly:scheduling:initial-delay:<Class>::<method>`, add-if-absent, no expiry) the first time it sees the
task, and every later process measures the window from that **first observation**.

**THE ANCHOR STORE MUST BE SHARED ACROSS PROCESSES under cron.** With `array`, every `schedule:run` re-anchors
at its own `now` and the task NEVER becomes due — it does not run early, it never runs — so `ScheduleWiringPass`
**warns as the Schedule is built** when a task carries a delay and the resolved anchor store cannot outlive the
process. A resident scheduler (`schedule:work`, `firefly:schedule`, Octane) keeps one process and honours the
delay over `array`. The `null` driver stores nothing at all: the gate sees that it cannot read back its own
write, logs it once per process and **admits** the tick rather than hanging the task in silence — a task that
runs without its delay is a visible wrong, a task that never runs is an invisible one.

**The window is armed once per cache key, not once per deployment.** The anchor has no expiry and nothing
forgets it, so by default an `initialDelay` is a one-off warm-up for a newly introduced task: the first
deployment waits its ten minutes, and every deployment after it reads an anchor from weeks ago, finds the
window long elapsed and runs on its first tick. That is a defensible default and it is **not** Spring's.
`firefly.scheduling.initial-delay.release` is how an application asks for the other reading — there is no
application start to measure from here, so the deployment names itself (a git sha, `APP_VERSION`, the release
directory), the anchor records which release armed it, and the first process of a different release arms a
fresh window and says so once. The value must change **once per deployment and never within one**: something
that varies per process (`uniqid()`, the PID, a boot timestamp) re-anchors every minute and the task never
runs.

**Switching the gate off refuses the boot.** Setting `firefly.scheduling.initial-delay.enabled` to `false`
in an application whose manifest carries an `initialDelay` is a `ConfigurationException` rather than a
parameter silently ignored — which is what happened for two releases and is the behaviour the key exists to
make impossible. An unparseable duration is refused at boot for the same reason: the predicate runs inside
`Event::filtersPass()`, which Laravel calls **outside** the try/catch that contains a failing task, so a throw
there would abort the whole `schedule:run` for that minute rather than skip one task.

### Configuration (`firefly.scheduling.*`, kebab-case)

| Key | Default | Meaning |
|---|---|---|
| `firefly.scheduling.lock.provider` | `'none'` | The `DistributedLock` backend a locked task uses: `none`, `cache`, or `postgres` (needs `firefly/scheduling-postgres`) — see [The `DistributedLock` port](#the-distributedlock-port). |
| `firefly.scheduling.initial-delay.enabled` | `true` | Apply `#[Scheduled(initialDelay:)]`. `false` **refuses to boot** an application whose manifest carries one, rather than accepting the parameter and ignoring it. |
| `firefly.scheduling.initial-delay.store` | `''` | Cache store the anchor lives in; `''` is the default store. Must be shared across processes (redis/memcached/database) wherever the scheduler is cron-driven. |
| `firefly.scheduling.initial-delay.release` | `''` | Names this deployment, so every release arms its own window. Empty keeps the permanent anchor — one window per cache key, for the life of the key. |

## Known-latent

These are carried-forward, documented limitations of the M7 shipment — not bugs:

- **`PgAdvisoryLock` has no TTL.** Postgres session-level advisory locks are held for the life of the
  database session and auto-release only when that connection dies — there is no expiry mechanism, so
  `tryAcquire()`'s `$ttlSeconds` argument is accepted (for `DistributedLock` interface parity) and
  **deliberately ignored**. This is a real divergence from `CacheLock`, whose TTL bounds how long a lock can
  be held even if `release()` is never called; a stuck Postgres session holds its advisory lock until the
  connection itself is torn down.
- **`fixedRate`/`fixedDelay` map to the *nearest* Laravel frequency, not an exact interval.** Laravel's
  scheduler has no arbitrary-interval DSL (no "every 42 seconds"); a configured rate that falls between two
  supported cadences rounds up to the next one (`Cadence`'s table above), so e.g. `fixedRate: '7m'` runs
  `everyTenMinutes()`, not every 7 minutes, and `'42s'` runs every minute. Sub-minute rates ARE honoured down
  to one second, but only under a resident scheduler (`schedule:work` / `firefly:schedule`); a cron-driven
  `schedule:run` starts the event once a minute and re-runs it until that minute ends.
- **An `initialDelay` window is armed once per cache key, not once per application start.** The anchor
  [Initial delay](#initial-delay) describes has no expiry and nothing prunes it, so with no
  `initial-delay.release` named a redeploy or a restart reads the old anchor and runs the task immediately:
  the delay is a one-off warm-up for a newly introduced task, which is a reading of the parameter and not
  Spring's. Naming the release buys Spring's reading; nothing buys "measured from this process's boot",
  because under cron that process is one minute old. What is genuinely out of reach is a delay honoured
  without a cross-process cache under a cron-driven scheduler: the framework warns, and cannot do better —
  there is nowhere else a fresh process per minute could read a first observation from.
- **A `#[Scheduled]` method outside `firefly.scan.paths` never registers.** The `ScheduledManifest` resolves
  to the `firefly:cache` artifact if present, otherwise an in-process scan of `firefly.scan.paths`, otherwise
  empty — so no hand-wiring is needed, but a task the scan cannot see is silently absent rather than an
  error.
