# Database Support Matrix

Every database-backed capability LaraFly ships, and what it does on each database Laravel speaks. "Proven" means
the `integration` CI lane for that server runs the capability's integration tests on every pull request
(`composer test:integration`, see [Integration Testing](modules/integration-testing.md)); SQLite runs in every lane
on a temporary file.

| Capability | PostgreSQL 16 | MySQL 8.4 | MariaDB 11 | SQL Server 2022 | SQLite |
|---|---|---|---|---|---|
| Same-transaction outbox (`firefly.eda.provider=database`) | ✓ SKIP LOCKED claim | ✓ SKIP LOCKED claim (8.0.1+), READ COMMITTED | ✓ SKIP LOCKED claim (10.6+), READ COMMITTED | ✓ READPAST claim | ✓ optimistic lease |
| Concurrent consumers and relays on one outbox | ✓ proven (two processes) | ✓ proven | ✓ proven | ✓ proven (CI lane) | ✓ proven (WAL) |
| `pg_notify` / `LISTEN` wake-up (`provider=postgres`) | ✓ | — | — | — | — |
| Durable eda dead letters (`firefly.eda.dead-letter.store=database`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Durable messaging dead letters (`firefly.messaging.dead-letter.store=database`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `lock.provider=database` (lease table, TTL honoured) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `lock.provider=mysql` (`GET_LOCK`, session-scoped) | — | ✓ | ✓ | — | — |
| `lock.provider=postgres` (advisory lock, session-scoped) | ✓ | — | — | — | — |
| `DataAccessException` translation (duplicate, integrity, grammar, lock wait) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Deadlock translation | ✓ | ✓ | ✓ | ✓ | — (no row locks) |
| `#[Transactional(timeout:)]` at the server | `SET LOCAL statement_timeout` | `max_execution_time` (reads) + `innodb_lock_wait_timeout` | `max_statement_time` + `innodb_lock_wait_timeout` | `SET LOCK_TIMEOUT` (lock waits) | busy timeout |
| `#[Lock(PESSIMISTIC_WRITE / READ)]` | ✓ `FOR UPDATE` / `FOR SHARE` | ✓ `FOR UPDATE` / `LOCK IN SHARE MODE` | ✓ | ✓ `UPDLOCK` / `HOLDLOCK` hints | — (compiles to a plain read) |
| Optimistic locking (`HasOptimisticLock`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Orchestration checkpoints, search attributes, recovery and dead letters (`firefly.orchestration.persistence.provider=database`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Event store appends, optimistic concurrency on `UNIQUE(aggregate_id, aggregate_version)` (`firefly.eventsourcing.store.provider=database`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Racing appenders: one aggregate (exactly one wins), many aggregates (all win, in version order) | ✓ proven (four processes) | ✓ proven | ✓ proven | ✓ proven (CI lane) | ✓ proven (one writer) |
| Commit-ordered global stream (a late committer is never skipped) | ✓ | ✓ READ COMMITTED numbering | ✓ READ COMMITTED numbering | ✓ READPAST numbering | ✓ |
| Snapshots and projection checkpoints (`firefly.eventsourcing.snapshot.store`, `projection.checkpoint-store`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Racing projection runners, every event projected once (fenced checkpoint) | ✓ proven (two processes) | ✓ proven | ✓ proven | ✓ proven (CI lane) | ✓ proven |
| Same-transaction event publishing through the outbox (`firefly.eventsourcing.publish.enabled`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Event-sourced orchestration persistence (`firefly.orchestration.persistence.provider=event-sourced`) | ✓ | ✓ | ✓ | ✓ | ✓ |
| ASYNC workflows on the `database` queue | ✓ | ✓ | ✓ | ✓ | ✓ |

How the outbox claim is chosen, and why every strategy is safe with any number of workers, is explained in
[EDA Database Outbox](modules/eda-database.md#claim-strategies). Servers older than the versions above fall back to
the portable optimistic lease automatically — the choice is made from the server's reported version, never from
the driver's name.
