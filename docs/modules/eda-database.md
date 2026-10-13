# EDA Database Outbox

`firefly/eda-database` is the portable same-transaction outbox behind `firefly.eda.provider=database`: the outbox
row is written on the aggregate's own connection inside its open transaction, on PostgreSQL, MySQL, MariaDB, SQL
Server or SQLite, and committed rows are claimed by **leases** so any number of `firefly:eda:consume` workers and
`firefly:outbox:relay` processes can run at once. `firefly/eda-postgres` is this package plus `pg_notify` on write
and a `LISTEN` wake-up in the consumer.

The full guide — configuration keys, the claim strategies, the delivery paths and the failure model — is completed
with the package; see also [EDA Brokers](eda-brokers.md).
