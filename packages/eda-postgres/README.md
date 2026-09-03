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

## Terminal in-process delivery

`EventListenerWiringPass` subscribes the app's compiled `#[EventListener]`s by calling `subscribe()` on the
bound `EventPublisher` — under this provider, `PostgresEventPublisher` — which records them on the **shared**
`SubscriberRegistry` singleton. `firefly:eda:consume` resolves that same registry and feeds every claimed row
into it, acking a row only after delivery returns. A handler that throws leaves the row `PENDING` with
`attempts + 1` (or `FAILED` past `max_attempts`), so a failed delivery is retried rather than lost.

## The optional relay

`firefly:outbox:relay` forwards committed `PENDING` rows to a **distinct** downstream broker. It is genuinely
optional: an app whose only consumers are `#[EventListener]` handlers never runs it. When you do, name the
downstream with `firefly.eda.postgres.relay.downstream_provider`, which accepts:

* `rabbitmq` / `kafka` — the shipped adapter, built from that package's own config keys
  (`firefly.eda.rabbitmq.exchange`, `firefly.eda.kafka.brokers`);
* the class-string of any `EventPublisher`, or the id of anything bound in the container;
* nothing at all, if you instead bind your own ready-made publisher under the container id
  `firefly.eda.relay.downstream` (`RelayDownstream::BINDING`) — the escape hatch for a downstream needing
  credentials or transport options this package has no business knowing about.

The relay never accepts the outbox writer as its own downstream: forwarding through it would re-INSERT every
claimed row as `PENDING` and claim it again forever. An unset or unresolvable value is a **loud** failure
(exit 1, naming the remedy) raised before any row is claimed — never a silent no-op that marks rows
`PUBLISHED` without forwarding them.

See [EDA Brokers](../../docs/modules/eda-brokers.md) for the full outbox reference.

Apache-2.0 © Firefly Software Solutions Inc.
