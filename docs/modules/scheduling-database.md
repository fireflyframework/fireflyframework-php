# Scheduling Database Locks

`firefly/scheduling-database` adds two `DistributedLock` backends for `#[Scheduled(lock: …)]` tasks, so a task runs
on at most one node per tick without Redis or PostgreSQL. See [Scheduling](scheduling.md) for the port, the other
backends and how a locked task is guarded.

| `firefly.scheduling.lock.provider` | Class | Works on | TTL | Holds a connection while the task runs |
|---|---|---|---|---|
| `database` | `DatabaseLeaseLock` | any Laravel database connection | honoured on every driver | no |
| `mysql` | `MySqlNamedLock` | MySQL, MariaDB | ignored (session-scoped) | yes — released when the session closes |

## `DatabaseLeaseLock`

One row per lock name in `firefly_scheduling_locks` (`name`, `locked_by`, `locked_until`, `locked_at`). Acquiring is
a conditional `UPDATE` that takes over a lease whose `locked_until` has passed, or — when the row does not exist —
an `INSERT`; a duplicate key means another node holds a live lease. The `INSERT` runs inside a savepoint when the
caller already has a transaction open, so a lost race never aborts the caller's unit of work (on PostgreSQL a failed
statement otherwise poisons the whole transaction). Releasing deletes the row only while it still carries this
node's acquisition token, so a task that ran past its TTL cannot release the lease another node has taken since. A
lock name is at most 255 characters; a longer one is refused rather than truncated.

Run `php artisan migrate` after selecting the provider. Lease instants are the application's clock in UTC, so keep
your nodes' clocks synchronised well within the lock TTLs.

## `MySqlNamedLock`

`GET_LOCK(name, 0)` / `RELEASE_LOCK(name)` — the MySQL-family counterpart of `PgAdvisoryLock`. The lock belongs to
the database session and is freed when the connection closes; there is no table and no TTL. Names longer than the
server's 64-character limit are hashed to `firefly:<sha1>`, identically on every node. The connection must be a
`mysql` or `mariadb` connection, or the first acquisition fails with a configuration error.

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `firefly.scheduling.lock.provider` | `none` | `database` or `mysql` select this package's backends. |
| `firefly.scheduling.lock.database.connection` | the default connection | The connection `DatabaseLeaseLock` writes its lease rows on. |
| `firefly.scheduling.lock.mysql.connection` | the default connection | The MySQL or MariaDB connection `MySqlNamedLock` takes its named locks on. |

Both backends are proven on every server they support by the `integration` CI lanes — see the
[database support matrix](../database-support.md).
