# Event-Driven Architecture (EDA)

`firefly/eda` is LaraFly's broker-backed event-transport bus (a pyfly `eda` analog): an `EventPublisher`
port with pattern-matched subscription, an envelope carrying event-type + payload + headers across process
boundaries, an in-memory default adapter plus a Laravel-queue async adapter, a config-driven retry/DLQ
helper, and a JSON serializer seam. It is a **transport** package — it does not replace, wrap, or depend on
the in-process event bus that already ships in `firefly/context`.

## Two event surfaces — not one

LaraFly has two distinct, unrelated event mechanisms. Confusing them is the single most common mistake:

| Surface | Attribute | Subscribes to | Delivery | Package |
|---|---|---|---|---|
| **In-process application events** | `#[AsEventListener]` | a PHP event **class** | synchronous, same process, no broker | `firefly/context` (M4) |
| **EDA broker bus** | `#[EventListener]` | an event-type **pattern** (fnmatch glob, e.g. `'user.*'`) | in-memory (sync) or queue (async, cross-process) | `firefly/eda` (M9) |

`#[AsEventListener]` and `#[EventListener]` are deliberately similar names on **different** surfaces —
`#[AsEventListener]` fires when your application dispatches a typed PHP object through
`ApplicationEventPublisher::publish(object $event)`; `#[EventListener]` fires when any envelope whose
`eventType` matches your pattern arrives on the eda bus, in-memory or via a queue worker. Bridging M8's
domain events onto the eda bus as integration events is **M10 (CQRS)** — `firefly/eda` does not touch the
in-process bus at all.

## The `EventPublisher` port

```php
interface EventPublisher
{
    public function subscribe(string $eventTypePattern, callable $handler): void;

    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void;

    public function start(): void;

    public function stop(): void;
}
```

A handler is a plain `callable(EventEnvelope): void`. `subscribe()` takes an fnmatch-style pattern
(`'user.*'`, `'order.created'`); `publish()` builds the envelope from its arguments and delivers it to every
matching subscriber. `start()`/`stop()` are no-ops on both shipped adapters — the seam exists for a real
broker adapter (SP-4) to open/close its connection there.

## The `EventEnvelope`

```php
final readonly class EventEnvelope
{
    public function __construct(
        public string $eventType,
        public string $destination,
        public array $payload = [],
        public array $headers = [],
        ?string $eventId = null,
        ?DateTimeImmutable $timestamp = null,
    ) {}

    public string $eventId;          // uuid4, random_bytes-derived — no ramsey/uuid, no reflection
    public DateTimeImmutable $timestamp;
}
```

Immutable; `withHeaders(array $extra): self` returns a copy with merged headers (used internally to stamp
`x-original-topic`/`x-exception` on a dead-lettered envelope). `toArray()`/`fromArray()` round-trip the
envelope as a plain array (timestamp as an ISO-8601 string), which is exactly the shape `JsonSerializer`
encodes.

## Adapters

### `InMemoryEventBus` — the default

A `SubscriberRegistry` (pattern → handler pairs) delivered to in subscription order via `fnmatch()`.
`publish()` builds the envelope and calls `deliver()` **synchronously** — every matching handler runs before
`publish()` returns. `start()`/`stop()` are no-ops. Zero external services; this is the skeleton default
(`firefly.eda.provider` unset or `memory`).

### `QueueEventBus` — the async adapter

Built on `illuminate/queue` + `illuminate/bus` (not `illuminate/events` — that's the in-process surface):

- `publish()` builds the envelope, wraps it in **one** `DispatchEventJob implements ShouldQueue`, dispatches
  it onto the configured connection/queue, and **returns immediately** — the handler has not run yet.
- The queue worker's `DispatchEventJob::handle()` resolves the process-local `EventPublisher` singleton —
  whose `SubscriberRegistry` was populated during *that worker's own boot* by `EventListenerWiringPass` from
  the same compiled manifest — and calls `deliver()`. A share-nothing worker therefore reconstructs the
  identical subscriber set every time it boots, so any listener present in the manifest is honoured by
  whichever worker happens to pick up the job.
- The `Dispatcher` is resolved **fresh from the container on every `publish()` call**, never cached on
  `$this` — the same rule `DispatcherEventPublisher` follows for the in-process bus — so `Bus::fake()` (bound
  after the adapter is constructed) still intercepts it.
- Under the `sync` queue driver, delivery collapses to synchronous — the same observable behaviour as
  `InMemoryEventBus` — which is exactly how the capstone tests exercise the async path without a running
  worker.

!!! note "Two independent retry layers"
    Laravel's own job-level retry/`failed_jobs` machinery governs `DispatchEventJob` itself (the envelope
    delivery attempt). The eda handler-level retry/DLQ described below is **completely independent** —
    it governs whether an individual `#[EventListener]` handler's own failure is retried and dead-lettered,
    on both adapters, regardless of whether the job layer ever retries anything. Don't confuse the two.

## `#[EventListener]` → scanner → manifest → wiring pass

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @param string|array<int, string> $patterns */
    public function __construct(string|array $patterns = [], public readonly int $order = 0) { /* ... */ }
}
```

`#[EventListener]` is **inert metadata only** — the same rule as every Firefly attribute. A single string
pattern is normalised to a one-element list; the attribute `IS_REPEATABLE`, so a method may subscribe to
several patterns (or several separately-ordered annotations) at once.

- **`EventListenerScanner`** (`packages/eda/src/Scanner/EventListenerScanner.php`) is the **sole reflection
  site** in the package (a grep-enforced invariant — `ReflectionFreeEdaTest`). It walks the app's PSR-4
  roots, reflects every public method carrying `#[EventListener]`, and emits one pure-array
  `EventListenerDescriptor` (`class`, `method`, `patterns`, `order`) per annotation. It runs only at cache
  time, never on a production request path.
- **`EventListenerManifest`** loads the `var_export`ed descriptor array back via `require`+map, with zero
  reflection — mirroring `ScheduledManifest`/`RouteManifest`/`TransactionalManifest`.
- **`EventListenerWiringPass`** runs at `BootPhase::WiringPasses` (1000), in *every* process — web request
  and queue worker alike. For each manifest row it wraps the target invocation in `RetryingEventHandler`
  (config-driven retries/delay + the bound `DeadLetterStore`) and calls `$bus->subscribe($pattern, $wrapped)`
  for each of the descriptor's patterns. The invoking closure resolves the target bean **fresh from the
  container on every dispatch** — never cached at registration — so it always observes the fully
  post-processed (possibly proxied) bean, exactly like `RegisterEventListenersPass` does for the in-process
  surface.

## Retry / DLQ model

`RetryingEventHandler::wrap(callable $handler, int $retries, float $retryDelay, ?DeadLetterStore $dlq): Closure`
**always** wraps the handler — there is deliberately no unwrapped fast path:

1. Invoke the handler.
2. On throw, retry up to `$retries` more times with **linear backoff**: attempt *N* (1-indexed) sleeps
   `retryDelay * N` seconds before the next try.
3. On final failure: if a `DeadLetterStore` is bound, store the envelope — enriched via `withHeaders()` with
   `x-original-topic` (the envelope's `destination`) and `x-exception` (the exception message) — into it and
   return (no exception escapes `publish()`); otherwise re-throw the original exception unchanged.

With `retries === 0` and no DLQ configured, the handler still runs through the wrapper — it just executes
once and re-throws unchanged on failure, the same observable result as an unwrapped call.

`firefly.eda.retries` and `firefly.eda.retry_delay` are read once by `EventListenerWiringPass` and applied to
**every** `#[EventListener]` in the app — this is a single config-driven policy, not a per-listener one (see
[Messaging](messaging.md) for the contrasting per-listener model).

```php
interface DeadLetterStore
{
    public function store(EventEnvelope $envelope, Throwable $cause): void;

    /** @return list<DeadLetterEntry> */
    public function all(): array;
}
```

`InMemoryDeadLetterStore` (the `#[ConditionalOnMissingBean]` default) is an in-process, append-only list of
`DeadLetterEntry` (envelope + exception class + exception message) — nothing is silently lost, but it does
not survive past the process. A durable adapter is SP-4 territory.

## Serializer seam

```php
interface Serializer
{
    public function serialize(EventEnvelope $envelope): string;

    public function deserialize(string $raw): EventEnvelope;
}
```

`JsonSerializer` is the **only** shipped implementation: `json_encode`/`json_decode` over
`EventEnvelope::toArray()`/`fromArray()`, `JSON_THROW_ON_ERROR`, re-wrapping any `JsonException` (or a
decoded value missing an expected envelope key) as a fail-loud `SerializationException`. Selecting any other
`firefly.eda.serialization_format` throws the same exception at boot — Avro/Protobuf are documented seams
for their own future opt-in packages, not implemented here. Neither shipped adapter actually calls the
serializer on its default path (in-memory delivers the envelope object directly in-process; the queue
adapter rides Laravel's own job serialization) — the seam exists for a future broker adapter that puts raw
bytes on a real wire.

## Config keys

| Key | Type | Default | Meaning |
|---|---|---|---|
| `firefly.eda.provider` | `memory`\|`queue` | `memory` | Selects `InMemoryEventBus` or `QueueEventBus` (`EdaAutoConfiguration`). |
| `firefly.eda.serialization_format` | string | `json` | Selects `Serializer`; anything but `json` throws `SerializationException`. |
| `firefly.eda.retries` | int | `0` | Handler-level retry count applied to every `#[EventListener]` by `EventListenerWiringPass`. |
| `firefly.eda.retry_delay` | float (seconds) | `0.0` | Linear-backoff base delay (`retry_delay * attempt`). |
| `firefly.eda.queue.connection` | string\|null | `null` (default connection) | Queue connection `QueueEventBus`/`DispatchEventJob` dispatch onto, when `provider=queue`. |
| `firefly.eda.queue.name` | string\|null | `null` (default queue) | Queue name, when `provider=queue`. |

!!! note "`destination` is a call-site argument, not a config key"
    `EventPublisher::publish(string $destination, ...)` takes the destination explicitly at the call site —
    it is not read from configuration. The test suite and capstones use `'firefly.events'` by convention as
    an app-facing default destination name, but no shipped code binds or reads a
    `firefly.eda.destinations` config key; nothing in `EdaAutoConfiguration` or the wiring pass consults one.
    Pick whatever destination string suits your application.

`EdaAutoConfiguration` (`#[Configuration] #[Order(1000)]`) binds the `EventPublisher`, `Serializer`, and
`DeadLetterStore` beans, each behind `#[ConditionalOnMissingBean]` so an application binding its own wins.
It is always-on — installing `firefly/eda` requires no configuration to boot with working (in-memory)
defaults.

## Example

```php
use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;

#[Component]
final class OrderNotifier
{
    #[EventListener('order.*')]
    public function onOrderEvent(EventEnvelope $envelope): void
    {
        // $envelope->eventType is e.g. "order.placed"; $envelope->payload carries the data.
    }
}

final class OrderService
{
    public function __construct(private readonly EventPublisher $events) {}

    public function place(int $orderId): void
    {
        $this->events->publish('firefly.events', 'order.placed', ['id' => $orderId]);
    }
}
```

## Known-latent

These are carried-forward, documented limitations of the M9 shipment — not bugs:

- **Real brokers are OUT.** Only the in-memory and Laravel-queue adapters ship. Kafka/RabbitMQ/Postgres/Redis
  land as their own `firefly/eda-<broker>` packages at **SP-4**, each gated behind
  `#[ConditionalOnProperty('firefly.eda.provider', '<x>')]`, patterned on `firefly/scheduling-postgres`.
- **The durable transactional outbox is deferred to SP-4.** M9 gives at-least-once delivery within a
  process/queue, not a DB-transaction-to-broker durability guarantee. The substrate is already built and
  untouched — M8's `DB::afterCommit` hook (`DomainEventDispatcher`) and the in-house `PgAdvisoryLock`
  (`firefly/scheduling-postgres`) — so SP-4's `firefly/eda-postgres` relay is a clean future drop-in.
- **Avro/Protobuf serializers, event `filter`, and an event circuit-breaker are thin/deferred seams.** JSON
  is the only shipped serializer; the others throw `SerializationException` until their own opt-in packages
  land. Filter and circuit-breaker are not implemented in M9. Publisher actuator-health wiring is **M12**.
- **The domain-event → integration-event bridge is M10 (CQRS).** M9 does not translate M8's in-process
  domain events onto the eda bus; that translation is a deliberately separate milestone.
- **"Async" is queue-backed, not coroutine-based.** Under the `sync` queue driver (or with no worker
  running), `QueueEventBus` delivers synchronously, identically to `InMemoryEventBus`. Genuine asynchronous,
  cross-process delivery requires a running queue worker (`queue:work`, Horizon, …).
- **App-level `#[EventListener]` manifests compile via `firefly:cache` — landing in M15.** Until then, an
  application supplies its compiled `EventListenerManifest` inline (bind it directly, or hand-run
  `EventListenerScanner` + the manifest compiler) rather than through an automated cache-warm command; a
  listener method absent from the compiled manifest silently never subscribes — the same compile-inline
  caveat as web routes and scheduled tasks.
- **`firefly/eda` and `firefly/messaging` are independent sibling packages** — see
  [Messaging § Sibling of `firefly/eda`](messaging.md#sibling-of-fireflyeda-no-shared-code) for why there is
  no dependency in either direction.
