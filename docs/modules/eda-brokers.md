# EDA Brokers (SP-4)

`firefly/eda-rabbitmq`, `firefly/eda-postgres`, and `firefly/eda-kafka` are real message-broker
adapters behind the `firefly/eda` (M9) `EventPublisher` port — the "real brokers" seam M9 deliberately
left for later (see [Event-Driven Architecture § Known-latent](eda.md#known-latent)). Each is its own
opt-in package, gated behind `firefly.eda.provider`, and each ships its own `HealthIndicator`. Installing
any of them without selecting it as the active provider is fully inert: no connection is opened, no
extension is required, and `/actuator/health` is unaffected.

This page assumes you have already read [Event-Driven Architecture](eda.md) — the `EventPublisher` port,
`EventEnvelope`, `#[EventListener]`, and the retry/DLQ model it describes are unchanged here. What's new
in SP-4 is (1) three adapters that put envelopes on a real wire instead of in-memory/queue, and (2) a
**consumer-loop SPI** in `firefly/eda` itself, because a real broker needs a long-running process to pull
messages back off the wire — something M9's `start()`/`stop()` never needed.

## The three adapters

| Package | Client library | Destination shape | DLQ | Health indicator name |
|---|---|---|---|---|
| `firefly/eda-rabbitmq` | `php-amqplib/php-amqplib` (AMQP 0-9-1) | `"exchange/routingKey"` (or a bare routing key against the default exchange) | Broker-native DLX (dead-letter exchange) | `rabbitmq` |
| `firefly/eda-postgres` | `ext-pdo_pgsql` (via Laravel's `ConnectionInterface`) | a destination string stored on the outbox row | Outbox row `status='FAILED'` | `postgres` |
| `firefly/eda-kafka` | `ext-rdkafka` (**OPTIONAL**) | a topic name | Dead-letter topic `<topic>.DLT` | `kafka` |

All three implement the same `EventPublisher::publish(string $destination, string $eventType, array
$payload, array $headers = []): void` signature `firefly/eda` defines, and all three ship an
`EventConsumer` (see [The consumer-loop SPI](#the-consumer-loop-spi-fireflyeda) below) with the identical
`subscribe(array)/start()/poll(int):?ReceivedEnvelope/ack(ReceivedEnvelope)/nack(ReceivedEnvelope,
bool)/stop()` shape.

## The `firefly.eda.provider` switch

`firefly.eda.provider` now has five values: `memory` (default) and `queue` bind the M9 in-process/queue
adapters (`EdaAutoConfiguration`, `#[Order(1000)]`); `rabbitmq`, `postgres`, and `kafka` each bind their own
`EventPublisher` + `EventConsumer` pair from `#[Order(900)]` autoconfigurations (`RabbitMqAutoConfiguration`,
`PostgresOutboxAutoConfiguration`, `KafkaAutoConfiguration`) — running *before* `EdaAutoConfiguration`'s
`#[ConditionalOnMissingBean(EventPublisher::class)]` so it backs off correctly (the
`firefly/scheduling-postgres` win-the-race precedent). Each broker's beans are additionally
`#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: '<broker>')]`-gated, so with any other
provider selected the whole package resolves nothing and requires nothing.

## Config keys

### RabbitMQ (`firefly/eda-rabbitmq`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.eda.rabbitmq.host` | `127.0.0.1` | AMQP host. |
| `firefly.eda.rabbitmq.port` | `5672` | AMQP port. |
| `firefly.eda.rabbitmq.user` | `guest` | AMQP user. |
| `firefly.eda.rabbitmq.password` | `guest` | AMQP password. |
| `firefly.eda.rabbitmq.vhost` | `/` | AMQP vhost. |
| `firefly.eda.rabbitmq.exchange` | `firefly.events` | The default topic exchange `publish()` declares/targets when a destination carries no `exchange/` prefix. |
| `firefly.eda.rabbitmq.queue` | `firefly.eda` | The durable work queue the consumer declares and binds every subscribed pattern to. |
| `firefly.eda.rabbitmq.dlx` | `firefly.events.dlx` | The dead-letter topic exchange the work queue's `x-dead-letter-exchange` argument points at. |
| `firefly.eda.rabbitmq.prefetch` | `10` | `basic_qos` prefetch count for the consumer. |

### Postgres (`firefly/eda-postgres`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.eda.postgres.connection` | the default DB connection | The named Laravel connection the outbox publisher/consumer/relay use — must be `pgsql` for `pg_notify`/`LISTEN` to activate (any other driver, e.g. `sqlite` in tests, silently skips the NOTIFY optimization and falls back to polling). |
| `firefly.eda.postgres.channel` | `firefly_eda_events` | The `LISTEN`/`NOTIFY` channel name and the outbox row's `channel` column value. |
| `firefly.eda.postgres.max_attempts` | `3` | How many `nack()`s (in-process consumer) or failed relay attempts an outbox row tolerates before it is marked `FAILED`. |
| `firefly.eda.postgres.relay.downstream_provider` | *(unset)* | **OPTIONAL.** `rabbitmq`\|`kafka` — when set, `firefly:outbox:relay` forwards `PENDING` rows to that distinct downstream broker. When unset, the relay command is a documented no-op and delivery is entirely the terminal in-process consumer's job. |

### Kafka (`firefly/eda-kafka`)

| Key | Default | Meaning |
|---|---|---|
| `firefly.eda.kafka.brokers` | `127.0.0.1:9092` | `metadata.broker.list` for both the producer and the consumer. |
| `firefly.eda.consumer.group_id` | `firefly` | The Kafka consumer group id (`group.id`). Namespaced under the broker-agnostic `firefly.eda.consumer.*` prefix rather than `firefly.eda.kafka.*` because a consumer group id is a Kafka-specific concept with no RabbitMQ/Postgres analog — it is read only by `KafkaAutoConfiguration`. |

!!! note "No config key activates a broker by itself"
    Installing `firefly/eda-postgres` (say) via Composer does nothing until `firefly.eda.provider=postgres`
    is set. Conversely, setting the provider without installing the matching package throws at boot (eda's
    own `#[ConditionalOnMissingBean]` default binds nothing useful, or — for Kafka specifically — a clear
    `RuntimeException` if `ext-rdkafka` is absent; see [The Kafka extension is OPTIONAL](#the-kafka-extension-is-optional-suggest-extension_loaded)).

## The genuine same-transaction outbox (`firefly/eda-postgres`)

M9 shipped only at-least-once delivery within a process/queue — publishing a domain event was never
atomic with the aggregate's own database write. `firefly/eda-postgres` closes that gap with a **genuine
same-transaction outbox**: the `firefly_eda_outbox` row is written *inside* the aggregate's own open
transaction, on the aggregate's own connection, so it commits or rolls back atomically with the aggregate
— no dual-write, no "commit then hope the publish succeeds."

### The seam: `PreCommitEventHook`

`firefly/data`'s `Domain\DomainEventDispatcher` (non-frozen — this is the one authorized non-`Version.php`
edit SP-4 makes) gained an optional constructor seam:

```php
interface PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void;
}
```

`DomainEventDispatcher::afterCommit()` — which already runs *during* the pre-commit drain, before
`DB::connection($connection)->afterCommit(...)` is even registered — now calls
`$this->preCommitHook?->handle($event, $connection)` first. The hook is `null` by default: with no
broker package installed (or any provider other than `postgres`), `DomainEventDispatcher` behaves exactly
as it did in M8 — publish deferred to `DB::afterCommit` only, zero behaviour change. `$connection` is
threaded through unchanged from the dispatcher, so a `#[Transactional(connection: 'x')]` aggregate's
outbox row lands on connection `x`, not some default connection.

`firefly/eda-postgres`'s `OutboxPreCommitHook` is the implementation: it resolves the aggregate's *own*
connection via the injected `ConnectionResolverInterface`, builds a `PostgresEventPublisher` on it, and
delegates to an `EdaCommandEventPublisher` (the same M10 mapping `HandlerManifest::destinations()` +
`CorrelationContext` uses for the after-commit bridge) — so the domain-event → envelope mapping
(event type, payload, per-event destination, `transaction_id`) is byte-for-byte identical between the
in-tx path and the ordinary after-commit path. Only non-`DomainEvent` objects are ignored by the hook (the
generic `ApplicationEventPublisher` after-commit dispatch still handles those).

### The INSERT + `pg_notify`

`PostgresEventPublisher::publish()` does one `INSERT ... RETURNING id` into `firefly_eda_outbox` on
**whatever connection it was constructed with** — inside a transaction, that INSERT enlists in it. When
the underlying driver is `pgsql`, the publisher also issues `SELECT pg_notify(?, ?)` with the row id, in
the **same transaction**. Postgres queues a `NOTIFY` fired inside a transaction and only delivers it once
that transaction actually commits — so the consumer's `LISTEN` wakes with low latency at exactly the
moment the row becomes visible to other connections, and never wakes for a row that gets rolled back.

Outbox columns (one source of truth, `OutboxSchema`): `id, destination, channel, event_type, payload,
headers, transaction_id, status, attempts, error_message, created_at, processed_at, failed_at`. `status`
is one of `PENDING` (just written) / `PUBLISHED` (delivered) / `FAILED` (exhausted `max_attempts`).

### Double-publish avoidance

With `provider=postgres`, both the in-tx `OutboxPreCommitHook` *and* the ordinary M10 after-commit bridge
(`DomainEventBridge` → `CommandEventPublisher` → `EdaCommandEventPublisher`) could in principle write the
same event to the outbox twice. `PostgresOutboxAutoConfiguration` prevents this by binding, all at
`#[Order(900)]` (ahead of `firefly/data`'s and `firefly/cqrs`'s `#[Order(1000)]` defaults):

- its own `DomainEventDispatcher` carrying the `OutboxPreCommitHook` (wins `DataAutoConfiguration`'s
  `#[ConditionalOnMissingBean(DomainEventDispatcher::class)]`), and
- a `NoOpEventPublisher` as the `CommandEventPublisher` (wins `CqrsAutoConfiguration`'s
  `#[ConditionalOnMissingBean(CommandEventPublisher::class)]`), silencing *only* the after-commit eda leg.

The result: a domain event is written to the outbox **exactly once** — in-tx, atomically with the
aggregate. The generic (non-eda) after-commit `ApplicationEventPublisher` dispatch is untouched, so
`#[AsEventListener]` in-process listeners keep working exactly as before.

## Two forward paths

Once a row is `PENDING` in the outbox, there are two distinct — and independent — ways it can leave that
state. Pick one (or neither, if you're only using RabbitMQ/Kafka directly and never installed
`firefly/eda-postgres`).

### (a) Terminal in-process delivery — `firefly:eda:consume`

This is the default, always-available path: `firefly:eda:consume` drives `PostgresEventConsumer`, which:

1. `subscribe()` issues `LISTEN <channel>` (pgsql only).
2. `poll($timeoutMs)` first waits (briefly, best-effort) on a `NOTIFY` via `NotificationWaiter` — guarded so
   *any* failure on that wait (including a deprecation-to-exception handler tripping on the
   soon-to-be-deprecated `pgsqlGetNotify()` fallback path) never crashes the poll loop — then, regardless of
   whether a notification arrived, **claims** the oldest `status='PENDING'` row with
   `SELECT ... FOR UPDATE SKIP LOCKED` (pgsql) or a plain `ORDER BY id` (sqlite, for tests). The poll-fallback
   claim also picks up rows that were written while no consumer was listening.
3. The claimed row is handed to `ConsumerLoop`, which delivers it to the `SubscriberRegistry` — driving
   every matching `#[EventListener]` handler, exactly as the in-memory/queue adapters do.
4. On success, `ack()` runs a guarded `UPDATE ... SET status='PUBLISHED' WHERE id=? AND status='PENDING'` —
   the `WHERE status='PENDING'` guard makes the ack idempotent. On a handler throw, `nack()` increments
   `attempts` and either resets the row to `PENDING` (retry) or — past `max_attempts` — marks it `FAILED`.

There is **no `EventPublisher::publish()` call anywhere in this path** — `PostgresEventConsumer` reads and
updates the outbox table directly, so there is no re-INSERT and no risk of self-reference.

This is a **durable `PENDING`→`PUBLISHED` status window**, not an in-memory high-water mark: a restarted
consumer resumes at the oldest still-`PENDING` row and never replays a `PUBLISHED` row. A **single**
consumer worker gives exactly-once in-process delivery. Running **multiple** workers concurrently degrades
to at-least-once — `FOR UPDATE SKIP LOCKED` plus the guarded `ack()` UPDATE prevent two workers from both
claiming or both acking the same row, but a worker that crashes *after* running the handler but *before*
its `ack()` commits will have another worker (or its own restart) redeliver that row. **Write your
`#[EventListener]` handlers idempotently** if you run more than one consumer worker. A single worker
consuming in `id` order also gives effective FIFO per outbox (not per-partition/per-key ordering like
Kafka — just the natural insertion order of one table).

### (b) The optional relay — `firefly:outbox:relay`

`firefly:outbox:relay` is a **distinct, optional** path that fronts a **different downstream broker** —
set `firefly.eda.postgres.relay.downstream_provider=rabbitmq|kafka` to enable it. When that key is unset,
running the command is a documented no-op (it logs and exits `SUCCESS` immediately): terminal delivery
via `firefly:eda:consume` is assumed instead.

`OutboxRelay::relayBatch()` claims a batch of `PENDING` rows (`FOR UPDATE SKIP LOCKED` on pgsql, inside a
short transaction so concurrent relay workers never double-claim; a plain per-row `WHERE id=? AND
status='PENDING'` guard on drivers without row locking), publishes each through the configured downstream
`EventPublisher`, and marks it `PUBLISHED` — or, on a publish failure, increments `attempts` and marks it
`FAILED` past `max_attempts`, exactly like the in-process consumer's `nack()`.

Both the command and `OutboxRelay`'s constructor **refuse a `PostgresEventPublisher` as the downstream** —
that would re-INSERT `PENDING` rows into the very same outbox, an infinite loop — throwing a `LogicException`
/ printing a clear error instead. The relay is for genuinely bridging to a *different* broker (e.g. you want
Kafka as your public-facing bus but still want the same-tx outbox guarantee for the write); it is not an
alternative in-process delivery mechanism.

```bash
php artisan firefly:outbox:relay --max-messages=100 --time-limit=60 --sleep=1 --batch-size=50
```

## `firefly:eda:consume` — bounds and signal stop

`firefly:eda:consume` (`firefly/eda`, always registered — inert until a broker `EventConsumer` is bound) is
the one long-running command all three brokers' terminal in-process delivery drives through:

```
firefly:eda:consume {--max-messages= : stop after N messages}
                     {--time-limit= : stop after N seconds}
                     {--sleep=0 : idle ms between empty polls}
                     {--poll-timeout=5000 : block ms per poll}
```

It resolves the active `EventConsumer` (fails loudly — "No broker EventConsumer is bound..." — if the
provider is `memory`/`queue`, which have no consumer loop; use `queue:work` for the queue provider),
derives concrete subscriptions from the compiled `EventListenerManifest` via `TopicSubscriptionResolver`
(the fnmatch-pattern → concrete-topic bridge — an AMQP routing key, a Kafka topic/regex, or a Postgres
`LISTEN` channel, depending on the adapter), and hands everything to `ConsumerLoop`.

`ConsumerLoop::run()` is the broker-agnostic drive loop: `poll → sink → ack`, or `nack(requeue: true)` on a
sink throw (at-least-once), until `--max-messages` or `--time-limit` trips, or a `SIGINT`/`SIGTERM` arrives.
Signals are registered once via `pcntl_signal` (a no-op if the `pcntl` extension isn't loaded) and dispatched
once per loop iteration via `pcntl_signal_dispatch()`, so a `kill`/Ctrl-C stops **cleanly between messages —
never mid-ack**. `--sleep` only applies between *empty* polls (`poll()` returned `null`); it never delays a
message that was actually received.

## DLQ surface per broker

Retry exhaustion looks different on each broker, by design — each uses its own broker-native mechanism
rather than routing through the in-memory `DeadLetterStore` the M9 in-process/queue adapters use:

- **RabbitMQ**: the consumer declares the work queue with an `x-dead-letter-exchange` argument pointing at
  a dedicated DLX topic exchange (`firefly.eda.rabbitmq.dlx`, default `firefly.events.dlx`). A broker-native
  `nack(requeue: false)` (an exhausted retry) is routed by RabbitMQ itself straight to the DLX — no
  application code copies the message anywhere.
- **Kafka**: there is no broker-native DLX equivalent, so `KafkaEventConsumer::nack(requeue: false)`
  re-produces the envelope to a **dead-letter topic** named `"<destination>.DLT"`, then commits the
  original offset so the exhausted record is never redelivered from its original topic.
- **Postgres**: there is no separate DLQ store at all — an exhausted outbox row is simply marked
  `status='FAILED'` (with `error_message` and `failed_at` populated) in place, on the same
  `firefly_eda_outbox` table. Query for `status='FAILED'` rows to inspect or reprocess them.

`nack(requeue: true)` — a retry that hasn't yet exhausted — behaves differently per broker too:
RabbitMQ's `basic_nack(requeue: true)` is an immediate broker-level redelivery; Kafka's consumer simply
leaves the offset uncommitted (the record redelivers on the next rebalance/restart of that consumer group,
since Kafka's log-offset model has no immediate-requeue primitive short of a manual `seek()`); Postgres
resets the row to `PENDING` so the next poll picks it straight back up.

## The Kafka extension is OPTIONAL (`suggest` + `extension_loaded`)

`firefly/eda-kafka` never hard-requires `ext-rdkafka` in `composer.json` — it only lists it under
`"suggest"`. Every `\RdKafka\*` class reference lives behind an `extension_loaded('rdkafka')` guard
(`KafkaProducerFactory::available()` / `KafkaConsumerFactory::available()`), checked as the **first**
statement of every method that would otherwise touch the extension. `KafkaAutoConfiguration` calls
`available()` before constructing either bean and throws a clear `RuntimeException` at *boot* time if
`firefly.eda.provider=kafka` is set but the extension isn't loaded — never a silent no-op, and never a
fatal from an unresolvable class at autoload time (a `use RdKafka\Producer;` import is only a compile-time
alias; it is safe on a machine with no `rdkafka` extension as long as nothing actually *instantiates* the
class, which `available()`'s guard prevents).

For **static analysis** on a machine without the extension installed (this repo's own CI/dev machine), the
root `composer.json` carries a dev-only `kwn/php-rdkafka-stubs` dependency, which resolves `\RdKafka\*` and
the `RD_KAFKA_*` constants for PHPStan — so `packages/eda-kafka` analyses clean (`[OK]`, no
`ignoreErrors`) even with `ext-rdkafka` absent.

## Kafka wildcard subscriptions: fnmatch → `^`-regex translation

`TopicSubscriptionResolver` emits fnmatch-style patterns (e.g. `order.*`, a bare `*`) — the same shape
`SubscriberRegistry`'s in-process matching already understands. librdkafka, however, treats any topic
string **not** prefixed with `^` as an exact literal topic name; subscribing to the literal string
`"order.*"` would never match any real topic. `KafkaEventConsumer::toTopic()` detects a `*` in the
destination and rewrites it to a `^`-prefixed librdkafka regex: `preg_quote()` escapes every regex
metacharacter (including turning `*` into the literal `\*`), the escaped `\*` is turned into `.*`, and the
whole thing is prefixed with `^` — so `order.*` becomes `^order\..*`. A destination with no `*` passes
through unchanged as a literal topic. `SubscriberRegistry`'s own fnmatch still does the fine-grained
post-receipt filter, so any broker-level over-matching (e.g. a bare `*` becoming `^.*`, matching every
topic) is harmless — it's the same over-match-then-filter contract `RabbitMqEventConsumer` uses for AMQP
routing keys.

## The consumer-loop SPI (`firefly/eda`)

M9's `EventPublisher` port had `start()`/`stop()` but no poll loop — there was nothing to poll, since
neither in-memory nor queue delivery needs one. A real broker does, so `firefly/eda` gained a small,
broker-agnostic SPI every adapter (and `firefly:eda:consume`) is built on:

```php
interface EventConsumer
{
    /** @param list<string> $destinations */
    public function subscribe(array $destinations): void;
    public function start(): void;
    public function poll(int $timeoutMs): ?ReceivedEnvelope; // null on timeout
    public function ack(ReceivedEnvelope $received): void;
    public function nack(ReceivedEnvelope $received, bool $requeue = true): void;
    public function stop(): void;
}
```

`ReceivedEnvelope` pairs the decoded `EventEnvelope` with an opaque `deliveryTag` (an AMQP delivery tag,
an outbox row id, or a Kafka `TopicPartition`+offset handle, depending on the adapter) that the *same*
adapter's `ack()`/`nack()` replays. `TopicSubscriptionResolver` bridges the fnmatch-pattern world
(`EventListenerManifest`) to each adapter's own concrete-subscription model. `ConsumerLoop` is the one
shared drive loop described above. None of this SPI is broker-specific — it's what makes `firefly:eda:consume`
a single command that works unmodified against RabbitMQ, Postgres, or Kafka.

## `@group('integration')` testcontainer harness

Every adapter ships a genuine end-to-end round-trip test (`RabbitMqRoundTripTest`,
`PostgresOutboxRoundTripTest`, `KafkaRoundTripTest`) tagged `->group('integration')` — excluded from the
default `vendor/bin/pest` run and from `composer check`. Each is gated behind both a required PHP extension
and an environment variable carrying real connection details, and additionally requires Docker via the
`firefly/testing` `RequiresDocker` trait (checked in a named base test case's `setUp()`, not mixed into an
anonymous Pest closure, so PHPStan can see `skipUnlessDocker()` statically):

| Adapter | Required extension | Environment variable |
|---|---|---|
| RabbitMQ | — (`php-amqplib` is a hard Composer dependency) | `FIREFLY_RABBITMQ_DSN` |
| Postgres | `ext-pdo_pgsql` | `FIREFLY_PG_DSN` (parsed as `host=…;port=…;dbname=…;user=…;password=…`) |
| Kafka | `ext-rdkafka` | `FIREFLY_KAFKA_BROKERS` |

Missing extension, missing environment variable, or no Docker daemon reachable — any one of these skips
the suite with a clear message, so `composer check` and ordinary CI runs never depend on a live broker.
Run them explicitly (with the broker/extension/Docker prerequisites satisfied) via:

```bash
FIREFLY_PG_DSN="host=127.0.0.1;port=5432;dbname=firefly;user=postgres;password=postgres" \
  vendor/bin/pest --group=integration
```

## Example: switching an app to the Postgres outbox

```php
// config/firefly.php
'eda' => [
    'provider' => 'postgres',
    'postgres' => [
        'channel' => 'firefly_eda_events',
        'max_attempts' => 3,
        // 'relay' => ['downstream_provider' => 'kafka'], // OPTIONAL — omit for terminal in-process delivery
    ],
],
```

```bash
composer require firefly/eda-postgres
php artisan migrate                 # creates firefly_eda_outbox
php artisan firefly:eda:consume     # terminal in-process delivery — run this as a long-lived worker
```

No application code changes: the same `#[EventListener]` handlers and the same `EventPublisher::publish()`
call sites that worked against `memory`/`queue` now run against a durable, same-transaction outbox.
