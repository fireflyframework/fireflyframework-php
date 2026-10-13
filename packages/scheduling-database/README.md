# firefly/scheduling-database

LaraFly's database-backed `DistributedLock` providers for `#[Scheduled(lock: …)]`: `DatabaseLeaseLock`
(`firefly.scheduling.lock.provider=database`), a lease row per lock name in `firefly_scheduling_locks` on any Laravel
database connection, honouring the TTL on every driver; and `MySqlNamedLock` (`firefly.scheduling.lock.provider=mysql`),
MySQL and MariaDB `GET_LOCK`/`RELEASE_LOCK` named locks held for the connection's session. Installing the package is
inert until one of the two providers is selected. See
[docs/modules/scheduling-database.md](../../docs/modules/scheduling-database.md).

Apache-2.0 © Firefly Software Solutions Inc.
