# firefly/scheduling

LaraFly's scheduling layer: a `DistributedLock` port (a ShedLock analog) with in-package `NoneLock` (the
zero-coordination default) and `CacheLock` (over Laravel's atomic `Cache::lock`) backends; a `#[Scheduled]`
attribute discovered by the package's SOLE reflection site (`ScheduledScanner`) into a compiled, require-loaded
`ScheduledManifest`; and a `ScheduleWiringPass` that lazily registers native Laravel scheduled tasks — each
task body wrapped in a lock guard (acquire → run → release) and its exceptions logged, never propagated. The
`postgres` lock backend (`PgAdvisoryLock`) ships in the separate `firefly/scheduling-postgres` adapter package.

Apache-2.0 © Firefly Software Solutions Inc.
