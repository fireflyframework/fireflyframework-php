# firefly/scheduling-postgres

LaraFly's Postgres scheduling adapter: `PgAdvisoryLock`, a `DistributedLock` backend over Postgres
SESSION-LEVEL advisory locks (`pg_try_advisory_lock` / `pg_advisory_unlock`). The lock is held for the life
of the DB session and auto-releases if the connection dies — there is no TTL, so `tryAcquire()`'s
`$ttlSeconds` argument is accepted for interface parity and deliberately ignored. Opts in behind
`firefly.scheduling.lock.provider=postgres` via `PgAdvisoryLockAutoConfiguration`'s
`#[ConditionalOnProperty]`; installing the package is otherwise inert.

Apache-2.0 © Firefly Software Solutions Inc.
