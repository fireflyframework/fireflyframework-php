# firefly/eda-postgres

The Postgres flavour of LaraFly's same-transaction outbox: `firefly/eda-database`'s outbox — the row written on
the aggregate's own connection inside its open transaction, rows claimed by leases so any number of consumers and
relays can run — plus two Postgres optimizations: `PostgresEventPublisher` raises `pg_notify` inside that
transaction, and `PostgresEventConsumer` `LISTEN`s and wakes on it. Auto-configured behind
`firefly.eda.provider=postgres`, with a `PostgresHealthIndicator`; the `firefly.eda.postgres.*` keys are aliases of
`firefly.eda.database.*`.

```bash
composer require fireflyframework/larafly
```

The library includes the PostgreSQL adapter. Enable `ext-pdo_pgsql` and set
`firefly.eda.provider` to `postgres` before migrating and starting delivery:

```bash
php artisan migrate                 # creates firefly_eda_outbox
php artisan firefly:eda:consume     # terminal in-process delivery
```

## Terminal in-process delivery

`EventListenerWiringPass` subscribes the app's compiled `#[EventListener]`s by calling `subscribe()` on the
bound `EventPublisher` — under this provider, `PostgresEventPublisher` — which records them on the **shared**
`SubscriberRegistry` singleton. `firefly:eda:consume` resolves that same registry and feeds every claimed row
into it, acking a row only after delivery returns. A handler that throws leaves the row `PENDING` with
`attempts + 1` (or `FAILED` past `max-attempts`), so a failed delivery is retried rather than lost.

## The optional relay

`firefly:outbox:relay` forwards committed `PENDING` rows to a **distinct** downstream broker. It is genuinely
optional: an app whose only consumers are `#[EventListener]` handlers never runs it. When you do, name the
downstream with `firefly.eda.database.relay.downstream-provider` (alias
`firefly.eda.postgres.relay.downstream_provider`), which accepts:

* `rabbitmq` / `kafka` — the shipped adapter, built from that package's own config keys
  (`firefly.eda.rabbitmq.exchange`, `firefly.eda.kafka.brokers`);
* the class-string of any `EventPublisher`, or the id of anything bound in the container — a shipped
  adapter's own class-string is treated exactly as its alias, so it is built from the same config keys and
  honours `firefly.eda.tracing.brokers.enabled` the same way;
* nothing at all, if you instead bind your own ready-made publisher under the container id
  `firefly.eda.relay.downstream` (`RelayDownstream::BINDING`) — the escape hatch for a downstream needing
  credentials or transport options this package has no business knowing about.

The relay never accepts the outbox writer as its own downstream: forwarding through it would re-INSERT every
claimed row as `PENDING` and claim it again forever. An unset or unresolvable value is a **loud** failure
(exit 1, naming the remedy) raised before any row is claimed — never a silent no-op that marks rows
`PUBLISHED` without forwarding them.

See [EDA Brokers](../../docs/modules/eda-brokers.md) and [EDA Database Outbox](../../docs/modules/eda-database.md)
for the full outbox reference.

Apache-2.0 © Firefly Software Solutions Inc.
