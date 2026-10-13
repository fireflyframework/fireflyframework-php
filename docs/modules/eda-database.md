# EDA Database Outbox

`firefly/eda-database` is the portable same-transaction outbox behind `firefly.eda.provider=database`. An
`EventPublisher::publish()` — and every domain event, through the pre-commit hook — writes one
`firefly_eda_outbox` row on the aggregate's own connection inside its open transaction, so the event commits or
rolls back with the aggregate on PostgreSQL, MySQL, MariaDB, SQL Server or SQLite. `php artisan firefly:eda:consume`
delivers committed rows to your `#[EventListener]` handlers; the optional `php artisan firefly:outbox:relay`
forwards them to a second broker. Rows are claimed by **leases**, so any number of consumers and relays may run
against one outbox. `firefly.eda.provider=postgres` ([EDA Brokers](eda-brokers.md)) is this package plus
`pg_notify` on write and a `LISTEN` wake-up in the consumer.

## Switching an application to the database outbox

Set the provider, uncomment the block the reference ships at its defaults, migrate, and run a consumer:

<!-- source: skeleton/config/firefly.php -->
```php
        // 'database' => [
        //     'connection' => 'mysql',
        //     'channel' => 'firefly_eda_events',
        //     'max-attempts' => 3,
        //     'claim-strategy' => env('FIREFLY_EDA_CLAIM_STRATEGY', 'auto'),
        //     'lease-seconds' => 300,
        //     'poll-interval-ms' => 500,
        //     'relay' => [
        //         'downstream-provider' => 'rabbitmq',
        //     ],
        // ],
```

```bash
php artisan migrate                 # creates firefly_eda_outbox (and adds the lease columns to an older one)
php artisan firefly:eda:consume     # terminal in-process delivery — run one or more as long-lived workers
```

## Configuration

| Key | Default | Meaning |
|---|---|---|
| `firefly.eda.provider` | `memory` | `database` selects this package; `postgres` selects the Postgres flavour, which reads the same keys. |
| `firefly.eda.database.connection` | the default connection | The connection the outbox writer (outside a transaction), the consumer and the relay use. A domain event is always written on the aggregate's own connection. Alias: `firefly.eda.postgres.connection`. |
| `firefly.eda.database.channel` | `firefly_eda_events` | The value of the row's `channel` column (and, under `postgres`, the `LISTEN`/`NOTIFY` channel). Alias: `firefly.eda.postgres.channel`. |
| `firefly.eda.database.max-attempts` | `3` | Failed deliveries (consumer) or forwards (relay) a row tolerates before it is marked `FAILED`. Alias: `firefly.eda.postgres.max_attempts`. |
| `firefly.eda.database.claim-strategy` | `auto` | `auto`, `skip-locked`, `read-past` or `optimistic-lease` — see below. |
| `firefly.eda.database.lease-seconds` | `300` | How long a claimed row belongs to its claimant. Size it above your slowest listener. |
| `firefly.eda.database.poll-interval-ms` | `500` | How long an idle consumer sleeps between claims (`postgres` waits on `NOTIFY` instead). |
| `firefly.eda.database.relay.downstream-provider` | *(unset)* | Only read by `firefly:outbox:relay`: `rabbitmq`, `kafka`, an `EventPublisher` class-string, or a container id. Alias: `firefly.eda.postgres.relay.downstream_provider`. |

When a `firefly.eda.database.*` key and its `firefly.eda.postgres.*` alias are both set, the database key wins, so an
application can move between the two providers without losing its settings.

## Claim strategies

A claim leases rows to one claimant: the strategy writes `claimed_by` and `claimed_until` on the rows it picked and
commits that before any listener runs or any forward is attempted. A row is claimable while it is `PENDING` and its
lease is empty or over; acknowledging, failing or relaying a row happens only while the claimant still holds it.
The strategies differ only in how they pick rows without two claimants picking the same one:

| Strategy | How it picks | Chosen by `auto` on |
|---|---|---|
| `skip-locked` | `SELECT … FOR UPDATE SKIP LOCKED` in a short claim transaction (MySQL and MariaDB run it `READ COMMITTED` so publishers never wait on its gap locks) | PostgreSQL; MySQL 8.0.1+; MariaDB 10.6+ |
| `read-past` | `SELECT TOP n … WITH (UPDLOCK, READPAST, ROWLOCK)` in a short claim transaction | SQL Server |
| `optimistic-lease` | an unlocked read, then one conditional `UPDATE … WHERE status = 'PENDING' AND (claimed_until IS NULL OR claimed_until < now)` per row; the row is won when exactly one row changed | SQLite; any server the probe does not recognise; MySQL before 8.0.1; MariaDB before 10.6 |

`auto` reads the server's version (`DatabaseCapabilities` in `firefly/data`), never the driver's name: a `mysql`
connection whose server is MariaDB is recognised as MariaDB. A forced strategy the server cannot run fails at the
first claim with an error naming the server and its version — it is never downgraded silently. The strategy is
resolved at the first claim, so booting an application opens no connection to find out.

Lease instants are the application's clock in UTC at millisecond precision; keep your nodes' clocks synchronised
(NTP) well within `lease-seconds`.

## Delivery and failure

- **Consumer** (`firefly:eda:consume`): claims one row, delivers it to the `SubscriberRegistry`, then marks it
  `PUBLISHED`. A listener that throws counts an attempt and frees the row for an immediate retry, or marks it
  `FAILED` past `max-attempts`. A row whose JSON cannot be decoded is dead-lettered at once (`FAILED`, no retry).
- **Relay** (`firefly:outbox:relay`): claims a batch, forwards each row to the downstream broker inside the trace
  the row carries, and marks it `PUBLISHED` (or counts the failure). It refuses the outbox writer as its downstream.
- **Several workers**: each row is delivered once while its worker lives within its lease. A worker that crashes
  mid-delivery leaves its row leased until the lease ends; then another worker delivers it — across a crash,
  delivery is at-least-once, so listeners should be idempotent.
- **Health**: under `provider=database` the actuator reports an `outbox` component — the driver, the strategy in
  use and the `PENDING` backlog, or `DOWN` with the error.

## Moving from `firefly/eda-postgres`

Nothing to change: `provider=postgres` keeps its beans, its health indicator and its table, and is now this outbox
plus the two Postgres optimizations. The classes that moved here keep their old names as deprecated subclasses for
one release (`Firefly\Eda\Postgres\Outbox\OutboxSchema`, `OutboxRow`, `OutboxRelay`, `RelayDownstream`,
`Firefly\Eda\Postgres\Console\OutboxRelayCommand`). Run `php artisan migrate` once to add the lease columns.

See the [database support matrix](../database-support.md) for what each server does, and [EDA Brokers](eda-brokers.md)
for the broker adapters.
