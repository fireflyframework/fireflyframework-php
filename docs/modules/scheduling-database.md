# Scheduling Database Locks

`firefly/scheduling-database` adds two `DistributedLock` backends for `#[Scheduled(lock: …)]`:
`firefly.scheduling.lock.provider=database` (`DatabaseLeaseLock`, a lease row per lock name on any database
connection) and `firefly.scheduling.lock.provider=mysql` (`MySqlNamedLock`, `GET_LOCK` on MySQL and MariaDB).
See [Scheduling](scheduling.md) for the port and the other backends.
