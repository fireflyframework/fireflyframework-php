# Scheduling

`firefly/scheduling` layers a Spring/pyfly-style `#[Scheduled]` attribute and a distributed-lock guard onto
Laravel's own task scheduler, so a scheduled method runs on Laravel's native `Schedule` — no bespoke
scheduler loop of its own — while still supporting the "runs on at most one node" guarantee a multi-instance
deployment needs.

## The `DistributedLock` port

`Firefly\Scheduling\Lock\DistributedLock` is a ShedLock analog:

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
    ) {}
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

`initialDelay` is accepted by the attribute and carried through the compiled descriptor
(`ScheduledDescriptor`) for forward compatibility, but **`ScheduleWiringPass` does not yet apply it** to the
registered Laravel `Event` — Laravel's frequency DSL has no native initial-delay-before-first-run primitive
to wire it onto. Set it if you like for self-documentation, but it currently has no runtime effect (see
[Known-latent](#known-latent)).

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
2. Maps the descriptor's trigger onto the returned `Event` (`applyFrequency()`): `cron` applies verbatim;
   `fixedRate`/`fixedDelay` parse to seconds and bucket onto the nearest of `everyMinute()`,
   `everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()`, `hourly()`,
   `daily()`, or `weekly()`.

### The lock guard (at-most-one-node)

The task closure itself is where the distributed lock actually gates execution:

```php
$lockName = $descriptor->lockName;
if ($lockName !== null && ! $lock->tryAcquire($lockName, $this->lockTtl($descriptor))) {
    return; // held elsewhere this tick — skip
}

try {
    $bean->{$descriptor->method}();
} catch (Throwable $exception) {
    $this->report($container, $descriptor, $exception); // logged, never rethrown
} finally {
    if ($lockName !== null) {
        $lock->release($lockName);
    }
}
```

An unlocked task (`lock` unset) always runs — every node that ticks runs it. A locked task's body runs on
whichever node's `tryAcquire()` wins the race for that tick; every other node silently skips (`return`, no
exception, no log) and the lock is released in `finally` once the winner's body completes. Note this guards
**our** `DistributedLock` — including the Postgres advisory adapter — rather than Laravel's own
cache-only `Event::onOneServer()`, so the configured backend (not Laravel's) governs multi-node exclusion.

A thrown exception from the task body is **logged and swallowed**, never propagated — a failing scheduled
task must never abort the rest of the scheduler run (pyfly `_invoke` parity). It logs via the bound
`Psr\Log\LoggerInterface` when one is available, falling back to `error_log()` otherwise.

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
  supported cadences rounds up to the next one (`applyFrequency()`'s bucket table above), so e.g.
  `fixedRate: '7m'` runs `everyTenMinutes()`, not every 7 minutes. Precise cron generation and sub-minute
  scheduling are documented as landing with **SP-5**'s cron shims.
- **`initialDelay` is accepted but not yet applied.** As noted above, the attribute parameter is captured
  through to the compiled descriptor but `ScheduleWiringPass` does not currently read it when registering the
  Laravel `Event` — no initial-delay offset is set. Laravel's frequency DSL has no native way to express "run
  once after an initial delay, then resume the normal cadence", so this is deferred to **SP-5** alongside the
  cron shims above. (`zone` **is** applied — see `#[Scheduled]` above.)
- **A `#[Scheduled]` method outside `firefly.scan.paths` never registers.** The `ScheduledManifest` resolves
  to the `firefly:cache` artifact if present, otherwise an in-process scan of `firefly.scan.paths`, otherwise
  empty — so no hand-wiring is needed, but a task the scan cannot see is silently absent rather than an
  error.
