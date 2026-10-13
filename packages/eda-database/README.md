# firefly/eda-database

LaraFly's portable same-transaction outbox. With `firefly.eda.provider=database`, an `EventPublisher::publish()` —
and every domain event, through the pre-commit hook — writes a `firefly_eda_outbox` row on the aggregate's own
connection inside its open transaction, on PostgreSQL, MySQL, MariaDB, SQL Server or SQLite. `firefly:eda:consume`
delivers committed rows to `#[EventListener]` handlers and the optional `firefly:outbox:relay` forwards them to a
downstream broker. Rows are claimed by leases through an `OutboxClaimStrategy` (SKIP LOCKED, READPAST or a portable
optimistic lease, chosen from the server's capabilities), so any number of consumers and relays may run at once.
`firefly/eda-postgres` builds on this package and adds `pg_notify`/`LISTEN`. See
[docs/modules/eda-database.md](../../docs/modules/eda-database.md).

Apache-2.0 © Firefly Software Solutions Inc.
