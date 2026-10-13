# Messaging

`firefly/messaging` is LaraFly's lower-level raw-bytes broker layer (a pyfly `messaging` analog): a
`MessageBrokerPort` moving opaque byte payloads by topic, with consumer-group semantics, an in-memory
default adapter plus a Laravel-queue async adapter (and [Kafka and RabbitMQ](#kafka-and-rabbitmq) listener
containers from the eda broker packages), and a broker-agnostic `#[MessageListener]` attribute
carrying its **own** per-listener retry/DLQ policy. There is no envelope, no event-type pattern, and no
serializer here — see [Event-Driven Architecture](eda.md) for that higher-level surface.

## The `MessageBrokerPort` port

<!-- source: packages/messaging/src/MessageBrokerPort.php -->
```php
interface MessageBrokerPort
{
    // …
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

<!-- source: packages/messaging/src/Message.php -->
```php
final readonly class Message
{
    // …
    public function __construct(
        public string $topic,
        public string $value,
        public ?string $key = null,
        public array $headers = [],
    ) {}
    // …
    public function withHeaders(array $extra): self
    {
        return new self($this->topic, $this->value, $this->key, array_merge($this->headers, $extra));
    }
}
```

`$value` is raw bytes — nothing here parses or deserialises it.

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
    coordination, which the Kafka and RabbitMQ providers have (see [Kafka and RabbitMQ](#kafka-and-rabbitmq)).
    Consumer-group round-robin is an in-memory-only nicety on the other adapters — do not rely on it once
    `firefly.messaging.provider=queue`.

## `#[MessageListener]` → scanner → manifest → wiring pass

<!-- source: packages/messaging/src/Attributes/MessageListener.php -->
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

<!-- source: packages/messaging/src/DeadLetter/DeadLetterStore.php -->
```php
interface DeadLetterStore
{
    public function store(Message $message, Throwable $cause): void;

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array;
}
```

This is a **separate class** from eda's `DeadLetterStore` (`Firefly\Messaging\DeadLetter\DeadLetterStore` vs
`Firefly\Eda\DeadLetter\DeadLetterStore`) — no shared code, no cross-package edge; it wraps a raw `Message`
rather than an `EventEnvelope`, faithful to the two ports' different payload shapes.

`firefly.messaging.dead-letter.store` selects it: `memory` (default) or `database` (`DatabaseDeadLetterStore`, one
`firefly_messaging_dead_letters` row per message on `firefly.messaging.dead-letter.connection`, the raw bytes
base64-encoded so they round-trip on every driver; run `php artisan migrate`).
`php artisan firefly:messaging:dead-letters:replay` republishes entries to their original topic (`--id`, `--topic`,
`--limit`, `--dry-run`, `--keep`).

## Config keys

| Key | Type | Default | Meaning |
|---|---|---|---|
| `firefly.messaging.provider` | `memory`\|`queue`\|`kafka`\|`rabbitmq` | `memory` | Selects the broker: `InMemoryMessageBroker` or `QueueMessageBroker` (`MessagingAutoConfiguration`); `kafka`/`rabbitmq` are bound by the eda adapters (see [Kafka and RabbitMQ](#kafka-and-rabbitmq)). |
| `firefly.messaging.queue.connection` | string\|null | `null` (default connection) | Queue connection `QueueMessageBroker`/`DispatchMessageJob` dispatch onto, when `provider=queue`. |
| `firefly.messaging.queue.name` | string\|null | `null` (default queue) | Queue name, when `provider=queue`. |
| `firefly.messaging.dead-letter.store` | `memory`\|`database` | `memory` | Where a `#[MessageListener(deadLetterTopic: …)]` message goes once its retries are exhausted — see above. |
| `firefly.messaging.dead-letter.connection` | string\|null | `null` | The connection `DatabaseDeadLetterStore` writes on (`null` = the default connection). |
| `firefly.messaging.listener.retry.max-attempts` | int | `5` | Attempts per delivery (the first included) on a listener container broker. |
| `firefly.messaging.listener.retry.initial-delay` | duration | `1s` | Exponential back-off after the first failure (seconds, or `250ms`/`30s`/`5m`/`1h`). |
| `firefly.messaging.listener.retry.multiplier` | float >= 1 | `2.0` | Back-off multiplier after each further failure. |
| `firefly.messaging.listener.retry.max-delay` | duration | `30s` | Back-off cap. |
| `firefly.messaging.listener.dead-letter.enabled` | bool | `true` | Dead-letter after the last attempt; false logs and skips. |
| `firefly.messaging.kafka.brokers` | string | `firefly.eda.kafka.brokers`, else `127.0.0.1:9092` | Kafka bootstrap list. |
| `firefly.messaging.kafka.group-id` | string | `firefly` | Group of a listener that names none. |
| `firefly.messaging.kafka.dlt-suffix` | string | `.DLT` | Dead-letter topic suffix. |
| `firefly.messaging.rabbitmq.exchange` | string | `firefly.messages` | Durable direct exchange. |
| `firefly.messaging.rabbitmq.dead-letter-exchange` | string | `''` (= `<exchange>.dlx`) | Dead-letter exchange. |
| `firefly.messaging.rabbitmq.prefetch` | int | `10` | `basic.qos` prefetch. |

There is no `firefly.messaging.retries`/`retry_delay` config key — on the `memory` and `queue` adapters
retry/DLQ policy lives entirely on each `#[MessageListener]` attribute (see above), not in configuration. The
`listener.*` keys apply only to a listener container broker — the Kafka and RabbitMQ providers — described next.

## Listener container brokers and `firefly:messaging:consume`

A broker that consumes from a real server (the Kafka and RabbitMQ adapters) implements
`Firefly\Messaging\Container\ListenerContainerBroker`, a `MessageBrokerPort` with two more methods:
`subscribeListener(string $topic, callable $handler, ?string $group, ListenerOptions $options)` and
`poll(int $timeoutMs): bool`. Its container owns retries and dead letters and acknowledges a delivery only once
its listeners have handled it or it has been dead-lettered, so `MessageListenerWiringPass` hands it the BARE
listener plus the listener's `ListenerOptions` instead of wrapping it in `RetryingMessageHandler` — wrapping it
too would retry every failure twice over.

Each delivery runs under a `RetryPolicy` through the shared `ListenerInvoker`: `firefly.messaging.listener.retry.*`
(five attempts, 1s doubling, capped at 30s by default — PyFly's listener container), unless the listener sets
`retries: N > 0`, which means N + 1 attempts with `retryDelay` as a linear back-off, exactly as on the in-memory
and queue adapters. A listener's `deadLetterTopic` replaces the broker's default dead-letter route. A
dead-lettered message carries `x-original-topic`, `x-exception` (the short exception class name),
`x-exception-message` and `x-delivery-attempts` (`DeadLetterHeaders`).

`php artisan firefly:messaging:consume {--max-messages=} {--time-limit=} {--poll-timeout=1000} {--sleep=0}` is
the worker: it polls one delivery at a time until a bound trips or a SIGINT/SIGTERM arrives (honored between
deliveries when ext-pcntl is loaded), then stops the broker. On `memory` (delivers in process) and `queue` (runs
in `php artisan queue:work`) it refuses with exit code 1 and the reason. Setting
`firefly.messaging.provider=kafka|rabbitmq` without the matching adapter bound refuses the boot instead of
falling back to the in-memory broker.

`MessagingAutoConfiguration` (`#[Configuration] #[Order(1000)]`) binds the `MessageBrokerPort` and
`DeadLetterStore` beans, each behind `#[ConditionalOnMissingBean]` so an application binding its own wins. It
is always-on — installing `firefly/messaging` requires no configuration to boot with working (in-memory)
defaults.

## Kafka and RabbitMQ

`firefly.messaging.provider=kafka` (from `firefly/eda-kafka`, needs ext-rdkafka) and `=rabbitmq` (from
`firefly/eda-rabbitmq`) bind a `ListenerContainerBroker`: the same `#[MessageListener]`s, consumed by
`php artisan firefly:messaging:consume` (`--max-messages`, `--time-limit`, `--poll-timeout`, `--sleep`).

- **Retries belong to the container.** A delivery is attempted `firefly.messaging.listener.retry.max-attempts`
  times (default 5) with an exponential back-off (1s, ×2, capped at 30s); a listener with `retries: N` gets N + 1
  attempts with its `retryDelay` as a linear back-off instead. On a container, `retries: 0` means "the default".
- **Then a dead letter.** Kafka produces the record to the listener's `deadLetterTopic` or `<topic>.DLT`; RabbitMQ
  republishes it to the listener's `deadLetterTopic`, or rejects it so the queue's dead-letter exchange
  (`<exchange>.dlx`) moves it to `<queue>.dlq`. Headers: `x-original-topic`, `x-exception` (short class),
  `x-exception-message`, `x-delivery-attempts` (Kafka adds `x-dlt-reason`, `x-dlt-source-topic`,
  `x-dlt-source-offset`). `firefly.messaging.listener.dead-letter.enabled=false` logs and skips instead.
- **At least once.** A record is committed / a message acknowledged only after its listeners handled it or it was
  dead-lettered; a dead letter that cannot be written leaves it uncommitted and stops the worker.
- **Groups are real.** Kafka runs one consumer per group (a listener without one joins
  `firefly.messaging.kafka.group-id`); RabbitMQ gives each group its own durable queue (`firefly.<topic>` without
  one). The RabbitMQ connection is the event bus's — the `firefly.eda.rabbitmq.*` host, port, user, password and vhost.

## Example

The package's own consumer fixture, which the wiring test subscribes and delivers to:

<!-- source: packages/messaging/tests/Fixtures/OrderConsumer.php -->
```php
use Firefly\Container\Attributes\Component;
use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Message;
// …
#[Component]
final class OrderConsumer
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[MessageListener(topic: 'orders')]
    public function consume(Message $message): void
    {
        $this->spy->record($message->topic);
    }
}
```

Add `retries: 3, deadLetterTopic: 'orders.DLT'` to that attribute and the same listener retries three times and
dead-letters what still fails. Publishing is the other half, and needs nothing but the port:

<!-- illustrative: an application's own publisher bean; the framework injects the port, it does not ship a publisher of yours -->
```php
use Firefly\Messaging\MessageBrokerPort;

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

- **The queue path drops consumer-group round-robin** (see the warning above) — `QueueMessageBroker` ignores
  `$group` entirely and broadcasts to every subscriber on a topic. Genuine competing-consumer semantics need
  a real broker's own group coordination, which the Kafka and RabbitMQ providers have.
- **"Async" is queue-backed, not coroutine-based** — under the `sync` queue driver (or with no worker
  running), `QueueMessageBroker` delivers synchronously (minus the group-drop above), same async→sync
  contract as the rest of LaraFly.
- **A consumer outside `firefly.scan.paths` never subscribes.** The `MessageListenerManifest` resolves to
  the `firefly:cache` artifact if present, otherwise an in-process scan of `firefly.scan.paths`, otherwise
  empty — no hand-wiring needed, but a listener the scan cannot see is silently absent rather than an error.
  Same shape as `firefly/eda`'s listeners.
- **`firefly/messaging` and `firefly/eda` are independent sibling packages** with no dependency in either
  direction — see [Sibling of `firefly/eda`](#sibling-of-fireflyeda-no-shared-code) above.
