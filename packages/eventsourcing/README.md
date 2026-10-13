# firefly/eventsourcing

LaraFly's event sourcing, shaped after the Java `fireflyframework-eventsourcing` library and PyFly's
`pyfly.eventsourcing`. An `EventStore` port (`memory`, `database`) appends events under optimistic
concurrency and reads them back as per-aggregate streams and as one global stream in commit order, safe under
concurrent appenders on PostgreSQL, MySQL, MariaDB, SQL Server and SQLite. On top of it:

- event-sourced aggregates and a snapshot-aware repository;
- snapshot stores and upcasters;
- projections with checkpoints, a `DistributedLock` lease, a health component and
  `php artisan firefly:eventsourcing:projections*`;
- same-transaction publishing through the eda-database outbox;
- an `event-sourced` orchestration execution store (`firefly.orchestration.persistence.provider=event-sourced`).

Installing the package is inert until an application uses an event store. The guide is
[docs/modules/eventsourcing.md](../../docs/modules/eventsourcing.md).

Apache-2.0 © Firefly Software Solutions Inc.
