# Messaging

`firefly/messaging` is LaraFly's lower-level raw-bytes broker layer (a pyfly `messaging` analog): a
`MessageBrokerPort` moving opaque byte payloads by topic, with consumer-group semantics, an in-memory
default adapter plus a Laravel-queue async adapter, and a broker-agnostic `#[MessageListener]` attribute
carrying its **own** per-listener retry/DLQ policy. There is no envelope, no event-type pattern, and no
serializer here — see [Event-Driven Architecture](eda.md) for that higher-level surface.

## The `MessageBrokerPort` port

```php
interface MessageBrokerPort
{
    public function publish(string $topic, string $value, ?string $key = null, array $headers = []): void;

    public function subscribe(string $topic, callable $handler, ?string $group = null): void;

    public function start(): void;

    public function stop(): void;
}
```

A handler is a plain `callable(Message): void`. `$value` is a raw byte string — nothing is parsed,
deserialized, or pattern-matched by this package; `$topic` is matched exactly, and `$group` opts a
subscriber into consumer-group round-robin (see below).

## The `Message` value object

```php
final readonly class Message
{
    public function __construct(
        public string $topic,
        public string $value,        // raw bytes
        public ?string $key = null,
        public array $headers = [],
    ) {}
}
```

Lower-level than eda's `EventEnvelope`: no `eventType`, no `eventId`/`timestamp`, no serializer round-trip —
just a topic, bytes, an optional partition/routing key, and string headers. Immutable;
`withHeaders(array $extra): self` returns a copy (used to re-key a dead-lettered message).

## Adapters

### `InMemoryMessageBroker` — the default

Requires `start()` before `publish()` — publishing to a broker that hasn't started throws
`MessagingException`. Delivery rules, verified against the shipped test suite:

- A subscriber with **no group** receives **every** message published to its topic (broadcast) — each
  groupless subscriber is delivered to independently; two groupless subscribers both see every message, they
  never split a shared rotation.
- Subscribers sharing a **named group** receive messages **round-robin** — one member per publish, cycling
  through the group's members in subscription order.
- **Each distinct group is its own delivery target.** Two different groups on the same topic each receive
  every message (their own round-robin copy); groups don't compete with each other, only members *within* a
  group do.

`stop()` flips `started` back to `false`, so `publish()` fails again until `start()` is called again. Zero
external services — this is the skeleton default (`firefly.messaging.provider` unset or `memory`).

### `QueueMessageBroker` — the async adapter

Same enqueue-then-worker-deliver shape as eda's `QueueEventBus`, over `illuminate/queue` + `illuminate/bus`:

- `publish()` wraps `(topic, value, key, headers)` in **one** `DispatchMessageJob implements ShouldQueue`,
  dispatches it onto the configured connection/queue, and returns immediately.
- The worker's `DispatchMessageJob::handle()` resolves the process-local `MessageBrokerPort` singleton —
  whose subscriptions were populated during that worker's own boot by `MessageListenerWiringPass` — and
  calls `deliver()`, invoking every subscriber registered for that topic.
- The `Dispatcher` is resolved fresh from the container on every `publish()` call, exactly like
  `QueueEventBus`, so `Bus::fake()` still intercepts it.

!!! warning "The queue path drops consumer-group round-robin"
    `QueueMessageBroker::subscribe()` accepts a `$group` argument (for interface parity) but **ignores it** —
    every subscriber on a topic is invoked on every delivered message, unconditionally. Genuine
    competing-consumer semantics (one message, one group member) require a real broker's own group
    coordination, which only lands with a broker adapter at **SP-4**. Consumer-group round-robin is an
    in-memory-only nicety until then — do not rely on it once `firefly.messaging.provider=queue`.

## `#[MessageListener]` → scanner → manifest → wiring pass

```php
#[Attribute(Attribute::TARGET_METHOD)]
final class MessageListener
{
    public function __construct(
        public string $topic,
        public ?string $group = null,
        public int $retries = 0,
        public float $retryDelay = 0.0,
        public ?string $deadLetterTopic = null,
    ) {}
}
```

`#[MessageListener]` is the **one** broker-agnostic attribute for all brokers — the `@KafkaListener`/
`@RabbitListener` equivalent, but there is deliberately no broker-specific variant (a locked design
decision). It is inert metadata only.

- **`MessageListenerScanner`** (`packages/messaging/src/Scanner/MessageListenerScanner.php`) is the **sole
  reflection site** in the package (grep-enforced by `ReflectionFreeMessagingTest`). It walks the app's PSR-4
  roots, reflects every public method carrying `#[MessageListener]`, and emits one pure-array
  `MessageListenerDescriptor` (`class`, `method`, `topic`, `group`, `retries`, `retryDelay`,
  `deadLetterTopic`) per annotation.
- **`MessageListenerManifest`** loads the `var_export`ed descriptor array back via `require`+map,
  reflection-free — mirroring eda's `EventListenerManifest`.
- **`MessageListenerWiringPass`** runs at `BootPhase::WiringPasses` (1000), in every process. For each
  manifest row it wraps the target invocation in `RetryingMessageHandler` using **that descriptor's own**
  `retries`/`retryDelay`/`deadLetterTopic` (not a global config setting — contrast eda, below), calls
  `$broker->subscribe($descriptor->topic, $wrapped, $descriptor->group)`, and finally calls `$broker->start()`
  once every listener is subscribed — so the in-memory broker is publish-ready the moment boot completes (a
  no-op on the queue adapter). As with eda, the invoking closure resolves the target bean fresh from the
  container on every dispatch, never caching it at registration.

## Retry / DLQ — per listener, not config-driven

This is the one deliberate contrast with [eda](eda.md#retry-dlq-model): eda's retry policy is one
config-driven setting applied to every `#[EventListener]`; messaging's is **carried on each
`#[MessageListener]` attribute individually** — different consumers on different topics can have entirely
different retry counts, delays, and dead-letter destinations.

`RetryingMessageHandler::wrap(callable $handler, int $retries, float $retryDelay, ?string $deadLetterTopic, DeadLetterStore $dlq): Closure`
always wraps the handler (no unwrapped fast path):

1. Invoke the handler.
2. On throw, retry up to `$retries` more times with **linear backoff** (attempt *N* sleeps
   `retryDelay * N` seconds).
3. On final failure: if `$deadLetterTopic` is set, store a **new** `Message` re-keyed to that topic — same
   `value`/`key`, headers merged with `x-original-topic` (the message's original topic) and `x-exception`
   (the exception message) — into the bound `DeadLetterStore`, and return (nothing escapes); otherwise
   re-throw the original exception unchanged.

```php
interface DeadLetterStore
{
    public function store(Message $message, Throwable $cause): void;

    /** @return list<DeadLetterEntry> */
    public function all(): array;
}
```

This is a **separate class** from eda's `DeadLetterStore` (`Firefly\Messaging\DeadLetter\DeadLetterStore` vs
`Firefly\Eda\DeadLetter\DeadLetterStore`) — no shared code, no cross-package edge; it wraps a raw `Message`
rather than an `EventEnvelope`, faithful to the two ports' different payload shapes.

## Config keys

| Key | Type | Default | Meaning |
|---|---|---|---|
| `firefly.messaging.provider` | `memory`\|`queue` | `memory` | Selects `InMemoryMessageBroker` or `QueueMessageBroker` (`MessagingAutoConfiguration`). |
| `firefly.messaging.queue.connection` | string\|null | `null` (default connection) | Queue connection `QueueMessageBroker`/`DispatchMessageJob` dispatch onto, when `provider=queue`. |
| `firefly.messaging.queue.name` | string\|null | `null` (default queue) | Queue name, when `provider=queue`. |

There is no `firefly.messaging.retries`/`retry_delay` config key — retry/DLQ policy lives entirely on each
`#[MessageListener]` attribute (see above), not in configuration.

`MessagingAutoConfiguration` (`#[Configuration] #[Order(1000)]`) binds the `MessageBrokerPort` and
`DeadLetterStore` beans, each behind `#[ConditionalOnMissingBean]` so an application binding its own wins. It
is always-on — installing `firefly/messaging` requires no configuration to boot with working (in-memory)
defaults.

## Example

```php
use Firefly\Container\Attributes\Component;
use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Message;
use Firefly\Messaging\MessageBrokerPort;

#[Component]
final class OrderConsumer
{
    #[MessageListener(topic: 'orders', retries: 3, deadLetterTopic: 'orders.DLT')]
    public function consume(Message $message): void
    {
        // $message->value is the raw byte payload published to "orders".
    }
}

final class OrderPublisher
{
    public function __construct(private readonly MessageBrokerPort $broker) {}

    public function send(string $payload): void
    {
        $this->broker->publish('orders', $payload);
    }
}
```

## Sibling of `firefly/eda` — no shared code

`firefly/eda` and `firefly/messaging` are **independent sibling** leaf packages: neither depends on, nor is
depended on by, the other — no Deptrac edge exists in either direction. This is a deliberate design call, not
an oversight:

- **Parity.** pyfly keeps `eda` and `messaging` as two separate modules with two separate ports, two
  separate listener decorators, and two separate retry/DLQ implementations. Faithfully mirroring that keeps
  both port surfaces true to their source.
- **Layering direction.** Messaging is lower-level (raw bytes) than eda (envelopes). The only
  non-inverting edge would be eda depending on messaging — but the in-memory eda default would then pay for a
  pointless in-process byte round-trip through a message broker on its default path, and pyfly's own eda
  adapters talk to brokers directly rather than through its messaging module.
- **Cost of independence is small.** Each retry/DLQ helper is genuinely different (eda wraps an
  `EventEnvelope`; messaging wraps a `Message`), so each package owning its own is faithful, not duplicated
  boilerplate. The `x-original-topic`/`x-exception` header convention is shared *by documentation*, not by a
  shared class.

## Known-latent

These are carried-forward, documented limitations of the M9 shipment — not bugs:

- **Real brokers are OUT.** Only the in-memory and Laravel-queue adapters ship. Kafka/RabbitMQ land as their
  own `firefly/messaging-<broker>` packages at **SP-4**, mirroring eda's broker-package plan.
- **The queue path drops consumer-group round-robin** (see the warning above) — `QueueMessageBroker` ignores
  `$group` entirely and broadcasts to every subscriber on a topic. Genuine competing-consumer semantics need
  a real broker's own group coordination, arriving with the SP-4 broker adapters.
- **"Async" is queue-backed, not coroutine-based** — under the `sync` queue driver (or with no worker
  running), `QueueMessageBroker` delivers synchronously (minus the group-drop above), same async→sync
  contract as the rest of LaraFly.
- **A consumer outside `firefly.scan.paths` never subscribes.** The `MessageListenerManifest` resolves to
  the `firefly:cache` artifact if present, otherwise an in-process scan of `firefly.scan.paths`, otherwise
  empty — no hand-wiring needed, but a listener the scan cannot see is silently absent rather than an error.
  Same shape as `firefly/eda`'s listeners.
- **`firefly/messaging` and `firefly/eda` are independent sibling packages** with no dependency in either
  direction — see [Sibling of `firefly/eda`](#sibling-of-fireflyeda-no-shared-code) above.
