# firefly/eda-postgres

The GENUINE same-transaction outbox adapter for `firefly/eda`: `PostgresEventPublisher` writes the
`firefly_eda_outbox` row on the aggregate's own connection, inside the aggregate's own open transaction,
via `OutboxPreCommitHook` — an implementation of `firefly/data`'s `PreCommitEventHook` seam — so the row
commits or rolls back atomically with the aggregate, no dual-write. An in-process `LISTEN`/`NOTIFY` +
poll `PostgresEventConsumer` claims committed `PENDING` rows (`SELECT ... FOR UPDATE SKIP LOCKED`),
drives matching `#[EventListener]` handlers, and marks them `PUBLISHED` (or `FAILED` past
`max_attempts`). An optional `firefly:outbox:relay` command forwards `PENDING` rows to a distinct
downstream broker. Auto-configured behind `firefly.eda.provider=postgres`, with a `PostgresHealthIndicator`.

```bash
composer require firefly/eda-postgres
php artisan migrate                 # creates firefly_eda_outbox
php artisan firefly:eda:consume     # terminal in-process delivery
```

See [EDA Brokers](../../docs/modules/eda-brokers.md) for the full outbox reference.

Apache-2.0 © Firefly Software Solutions Inc.
