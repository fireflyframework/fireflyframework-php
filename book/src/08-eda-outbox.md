<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 8</span>

# Event-Driven Architecture and the Transactional Outbox {.chtitle}

By the end of this chapter you will know the two, deliberately distinct event surfaces LaraFly ships and why confusing them is the single most common mistake; the `EventPublisher` port and the `EventEnvelope` it carries; how `#[EventListener]` matches an event's **type name** — not its destination — a real gotcha the `samples/lumen` build hit while wiring `LedgerProjector` in Chapter 6; the in-memory and queue adapters; the retry/dead-letter model every listener is wrapped in; and — closing the durability gap the domain→integration bridge leaves open — the genuine **same-transaction outbox** `firefly/eda-database` writes atomically with the aggregate that raised the event, on any of the five databases; how its consumers lease rows so that any number of them can run at once; the optional `firefly:outbox:relay`; what `firefly/eda-postgres` adds on top; where a dead letter goes when it must outlive the process (`firefly:eda:dead-letters:replay`); and how `firefly/messaging` consumes from Kafka and RabbitMQ (`firefly:messaging:consume`).

!!! note "New term: integration event"
    Chapter 6 built **domain events** — `WalletOpened`, `FundsDeposited` — facts an aggregate raises for consumers inside the *same* process and the *same* deployable. An **integration event** is the same fact re-emitted across a process or service boundary: a JSON-ish envelope with a type name, a payload, and routing headers, carried by a broker (or, in-process, by the very same bus). This chapter is about the machinery on the far side of that boundary, and the bridge that gets a domain event onto it.

---

## Two event surfaces — not one

LaraFly ships two unrelated event mechanisms that happen to share a similar-looking attribute name:

| Surface | Attribute | Subscribes to | Delivery | Package |
|---|---|---|---|---|
| In-process application events | `#[AsEventListener]` | a PHP event **class** | synchronous, same process, no broker | `firefly/context` |
| EDA broker bus | `#[EventListener]` | an event-type **pattern** (fnmatch glob, e.g. `'user.*'`) | in-memory (sync) or queue (async) | `firefly/eda` |

`#[AsEventListener]` fires when application code calls `ApplicationEventPublisher::publish(object $event)` with a typed PHP object — the seam Chapter 6's after-commit dispatch itself rides on (`DomainEventDispatcher` calls exactly this to publish a drained `DomainEvent`). `#[EventListener]`, this chapter's subject, fires when an `EventEnvelope` whose `eventType` string matches your pattern arrives on the **eda** bus — in-memory, via a queue worker, or (this chapter's second half) via an outbox row. Neither package depends on the other; nothing in `firefly/eda` even imports Illuminate's `Dispatcher`.

---

## The `EventPublisher` port and the `EventEnvelope`

Everything in this chapter routes through one small port:

<!-- source: packages/eda/src/EventPublisher.php -->
```php
interface EventPublisher
{
    public function subscribe(string $eventTypePattern, callable $handler): void;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void;

    public function start(): void;

    public function stop(): void;
}
```

`subscribe()` takes an fnmatch-style pattern (`'user.*'`, `'order.created'`, a bare `'*'`); `publish()` takes a destination string, an event-type string, a payload, and optional headers, and builds the envelope every matching subscriber receives:

<!-- source: packages/eda/src/EventEnvelope.php -->
```php
final readonly class EventEnvelope
{
    // …
    public function __construct(
        public string $eventType,
        public string $destination,
        public array $payload = [],
        public array $headers = [],
        ?string $eventId = null,
        ?DateTimeImmutable $timestamp = null,
    // …
    public DateTimeImmutable $timestamp;
// …
}
```

`eventType` and `destination` are two genuinely different things, and the rest of this chapter hinges on that distinction: `destination` is *where* the envelope is routed on the wire (a queue name, an outbox row's `destination` column); `eventType` is *what fact it carries*, and it is the **only** field a `#[EventListener]` pattern is ever matched against.

`withHeaders(array $extra): self` returns a copy with merged headers — used internally to stamp `x-correlation-id`, and (you'll meet this again below) `x-original-topic`/`x-exception` on a dead-lettered envelope. `start()`/`stop()` are no-ops on the in-memory and queue adapters; the outbox's own consumer (later in this chapter) is where they start doing real work.

---

## `#[EventListener]` and the eventType-match gotcha

<!-- source: packages/eda/src/Attributes/EventListener.php -->
```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @var list<string> */
    public readonly array $patterns;
    // …
    /**
     // …
     */
        // …
        $this->patterns = is_string($patterns) ? [$patterns] : array_values($patterns);
    // …
    }
}
```

`#[EventListener]` is **inert metadata only**, same rule as every Firefly attribute — a single string pattern normalises to a one-element list, and `IS_REPEATABLE` lets one method carry several separately-ordered subscriptions. The subscription itself happens inside the shared registry both shipped adapters compose:

<!-- source: packages/eda/src/Bus/SubscriberRegistry.php -->
```php
final class SubscriberRegistry
{
    /** @var list<array{pattern: string, handler: callable}> */
    private array $subscribers = [];

    public function subscribe(string $pattern, callable $handler): void
    {
        $this->subscribers[] = ['pattern' => $pattern, 'handler' => $handler];
    }

    public function deliver(EventEnvelope $envelope): void
    {
        foreach ($this->subscribers as $subscriber) {
            if (fnmatch($subscriber['pattern'], $envelope->eventType)) {
                ($subscriber['handler'])($envelope);
            }
        }
    }
}
```

Read that `fnmatch()` call closely: it matches `$subscriber['pattern']` against `$envelope->eventType` — **never** against `$envelope->destination`. This is exactly the gotcha the `samples/lumen` build hit wiring `LedgerProjector` in Chapter 6, and it is worth restating in full here because it is the single easiest way to wire a listener that silently never fires. Every wallet domain event carries `#[PublishDomainEvent('wallet.events')]` — that string is the **destination** the event routes to once it crosses into the integration-event world. `LedgerProjector`'s own docblock states the rule as a warning for exactly this reason:

<!-- source: samples/lumen/src/Application/Listener/LedgerProjector.php -->
```php
/**
 // …
 * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
 * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
 * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
 * would therefore never match any wallet event and this projector would silently never fire.
 */
    // …
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // …
        $walletId = $envelope->payload['walletId'] ?? $envelope->payload['sourceWalletId'] ?? '';
        $amountMinor = $envelope->payload['amountMinor'] ?? 0;
        $balanceMinor = $envelope->payload['balanceMinor'] ?? 0;

        LedgerEntry::query()->create([
            'wallet_id' => is_string($walletId) ? $walletId : '',
            'event_type' => $envelope->eventType,
            'amount_minor' => is_int($amountMinor) ? $amountMinor : 0,
            'balance_minor' => is_int($balanceMinor) ? $balanceMinor : 0,
            'occurred_at' => now(),
        ]);
    }
}
```

A tempting-looking `#[EventListener(['wallet.*'])]` — reasoning by analogy from the destination string — compiles, deploys, and then never once invokes `onWalletEvent()`, because no wallet event's `eventType` (`'FundsDeposited'`, `'WalletOpened'`, …) ever begins with `'wallet.'`; that word only ever appears in the *destination*. Chapter 6 showed you `LedgerProjector`'s full source and its consequences for the read model; this chapter is where the rule it depends on actually lives.

!!! warning "`eventType`, never `destination`"
    Whenever you write a `#[EventListener]` pattern, ask "does this glob match the short class name of the event I want, not the queue/topic string it happens to route through?" The two are unrelated strings that are easy to conflate precisely because they often *look* related (`'wallet.events'` destination, `'WalletOpened'` event type) — `fnmatch()` only ever sees the second one.

A second, minimal illustration from `firefly/eda`'s own documentation makes the call site concrete, pattern and imperative publish together:

<!-- illustrative: the reader's own #[EventListener] over an event their application publishes -->
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
        $this->events->publish('orders', 'order.placed', ['id' => $orderId]);
    }
}
```

Here the destination (`'orders'`) and the event type (`'order.placed'`) are deliberately different strings, and the `'order.*'` pattern matches the **second** one — precisely so this example cannot be mistaken for evidence that patterns ever look at the destination.

`EventListenerScanner` (`packages/eda/src/Scanner/EventListenerScanner.php`) is the sole reflection site in `firefly/eda`, walking the app's PSR-4 roots once and compiling every `#[EventListener]` method into an `EventListenerDescriptor`; `EventListenerManifest` loads the result with zero reflection, exactly the `HandlerManifest` idiom Chapter 7 just showed you. `firefly:cache` runs this scanner as one of its fourteen pairs, writing `bootstrap/cache/firefly/event-listeners.php`; `FireflyCacheServiceProvider` binds it, overriding the empty default an uncached app would otherwise boot with. `EventListenerWiringPass` then walks that manifest at boot — in *every* process, web request and queue worker alike — wraps each target invocation in the retry/DLQ decorator below, and calls `$bus->subscribe($pattern, $wrapped)` per pattern, resolving the target bean **fresh from the container on every dispatch**.

---

## Retry and dead-letter: every listener is wrapped, always

`RetryingEventHandler::wrap()` decorates *every* subscribed handler — there is deliberately no unwrapped fast path:

<!-- source: packages/eda/src/DeadLetter/RetryingEventHandler.php -->
```php
final class RetryingEventHandler
{
    // …
    public static function wrap(callable $handler, int $retries, float $retryDelay, ?DeadLetterStore $dlq): Closure
    {
        return static function (EventEnvelope $envelope) use ($handler, $retries, $retryDelay, $dlq): void {
            $attempt = 0;

            while (true) {
                try {
                    $handler($envelope);

                    return;
                } catch (Throwable $e) {
                    $attempt++;

                    if ($attempt > $retries) {
                        if ($dlq === null) {
                            throw $e;
                        }

                        $dlq->store(
                            $envelope->withHeaders([
                                'x-original-topic' => $envelope->destination,
                                'x-exception' => $e->getMessage(),
                            ]),
                            $e,
                        );

                        return;
                    }

                    if ($retryDelay > 0.0) {
                        usleep((int) ($retryDelay * $attempt * 1_000_000));
                    }
                }
            }
        };
    }
}
```

A throw retries up to `firefly.eda.retries` more times with **linear** backoff (attempt *N* sleeps `retryDelay * N` seconds); on final failure, a bound `DeadLetterStore` receives the envelope — enriched with `x-original-topic`/`x-exception` — and the failure is swallowed; with no store bound, the original exception re-throws unchanged. With `retries === 0` and no DLQ, the handler still runs through the wrapper, just once, the same observable outcome as an unwrapped call. `InMemoryDeadLetterStore` — an in-process, append-only list of `DeadLetterEntry` (envelope + exception class + message) — is the `#[ConditionalOnMissingBean]` default: nothing is silently lost, but nothing survives the process either.

!!! note "Two independent retry layers"
    On the `QueueEventBus` adapter, Laravel's own job-level retry/`failed_jobs` machinery governs the **envelope delivery** job itself. `RetryingEventHandler` governs whether an individual `#[EventListener]` **handler's own failure** is retried and dead-lettered — completely independently, on both adapters. A job that delivers successfully but whose listener throws is a `RetryingEventHandler` concern, not a queue-retry concern.

---

## In-memory and queue: the two M9 adapters

`InMemoryEventBus` is the skeleton default (`firefly.eda.provider` unset, or `memory`) — synchronous, zero external services:

<!-- source: packages/eda/src/Bus/InMemoryEventBus.php -->
```php
final class InMemoryEventBus implements EventPublisher
{
    // …
    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }
    // …
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        // Delivery is synchronous, so the consume side is NESTED in the publish side: the PRODUCER span
        // wraps the CONSUMER span, and the envelope carries the traceparent between them exactly as it
        // would across a broker.
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $envelope = new EventEnvelope($eventType, $destination, $payload, $headers);

            $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
        });
    }

    public function start(): void {}

    public function stop(): void {}
}
```

`publish()` builds the envelope and hands it to `SubscriberRegistry::deliver()` **synchronously** — every matching handler has already run by the time `publish()` returns, and a handler that throws throws into the publishing call. The two `EdaTracing` calls wrapped around it are a no-op until Chapter 11 installs a real tracer; what they buy is that the in-memory bus emits the same producer-then-consumer span pair a broker would, so a trace does not change shape when you change provider.

`QueueEventBus` (`firefly.eda.provider=queue`) keeps the identical `EventPublisher` contract but makes `publish()` fire-and-forget:

<!-- source: packages/eda/src/Bus/QueueEventBus.php -->
```php
final class QueueEventBus implements EventPublisher
{
    // …
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $job = (new DispatchEventJob(new EventEnvelope($eventType, $destination, $payload, $headers)))
                ->onConnection($this->connection)
                ->onQueue($this->queue);

            // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
            $this->container->make(Dispatcher::class)->dispatch($job);
        });
    }

    public function deliver(EventEnvelope $envelope): void
    {
        $this->tracing->traceConsume($envelope, fn (EventEnvelope $received) => $this->registry->deliver($received));
    }
// …
}
```

Same two seams, split across two processes: `publish()` is the producer side in the web request, `deliver()` the consumer side on the worker, and the traceparent riding in the envelope's headers is the only thing joining them.

`publish()` returns before any listener has run — delivery happens on whichever queue worker picks up the `DispatchEventJob`. That worker's own boot repopulates its `SubscriberRegistry` from the same compiled manifest `EventListenerWiringPass` reads everywhere, so a share-nothing worker reconstructs the identical subscriber set every time it starts. Under the `sync` queue driver, delivery collapses to the same synchronous behaviour as `InMemoryEventBus` — which is exactly how a test exercises the async path with no worker actually running.

---

## The domain → integration-event bridge

Chapter 6 showed you the *effect* of the bridge — a committed `WalletOpened` reaching `LedgerProjector` as an `EventEnvelope`. Here is the mechanism. Every `DomainEvent` committed through `firefly/data`'s after-commit dispatch (Chapter 6's closing section) is also published, in-process, to `ApplicationEventPublisher` — the very `#[AsEventListener]` surface this chapter opened by contrasting with `#[EventListener]`. Because Illuminate's own dispatcher matches an object event only by its **concrete class** (plus interfaces, never parent classes), a single `#[AsEventListener]` typed on the abstract `DomainEvent` base could never fire for any concrete subclass. `DomainEventBridgeWiringPass` works around exactly this limitation with one guarded wildcard listener instead:

<!-- source: packages/cqrs/src/Boot/DomainEventBridgeWiringPass.php -->
```php
final class DomainEventBridgeWiringPass implements BootPass
{
    // …
    public function run(BootContext $context): void
    {
        $container = $context->container;
        // …
        $dispatcher = $container->make('events');

        $dispatcher->listen('*', DispatcherEventPublisher::guardListener(
            // …
            static function (string $eventName, array $payload) use ($container): void {
                $event = $payload[0] ?? null;
                if ($event instanceof DomainEvent) {
                    $container->make(DomainEventBridge::class)->publish($event);
                }
            },
        ));
    }
}
```

`DomainEventBridge::publish()` maps the `DomainEvent` onto the eda `EventPublisher` port through `EdaCommandEventPublisher`: `eventType` = `$event->eventType()` (the short class name — the very string `#[EventListener]` patterns match against), `payload` = the event's public fields via `get_object_vars()`, and `destination` = an explicit override, else the event's `#[PublishDomainEvent(destination:)]` (`'wallet.events'` for every wallet event), else `firefly.cqrs.default_destination` (`'cqrs.events'`). The active correlation id (Chapter 7's `CorrelationContext`) is stamped into the envelope's `x-correlation-id` header.

<!-- source: packages/cqrs/src/Event/DomainEventBridge.php -->
```php
final class DomainEventBridge
{
    // …
    public function publish(DomainEvent $event): void
    {
        $eventClass = $event::class;

        try {
            $this->publisher->publish($event);
        } catch (Throwable $e) {
            if ($this->failureStrategy === EventFailureStrategy::Raise) {
                throw $e instanceof CommandProcessingException ? $e : new CommandProcessingException($eventClass, $e);
            }

            $this->logger?->error(
                "CQRS domain-event bridge failed to publish [{$eventClass}]: {$e->getMessage()}",
                ['exception' => $e],
            );
        }
    }
}
```

This runs **after** the aggregate's own transaction has already committed — the write already succeeded by the time this code executes. `firefly.cqrs.event_failure_strategy` (default `log`) decides what happens if the broker publish itself then fails: `log` swallows and logs it — the command's result stands; the integration publish was best-effort. `raise` re-throws it, wrapped in `CommandProcessingException`, surfacing the post-commit failure to the original caller even though the underlying write is already durable. Either way, the bridge fires **only** for a genuinely committed unit of work: a rolled-back transaction raises no `DomainEvent` at all (Laravel discards `afterCommit` callbacks on rollback), so nothing is ever published for it — the same guarantee Chapter 6's after-commit model already gave you for in-process listeners, now extended across the process boundary.

::: figure art/figures/cqrs-eda-bridge.svg | Figure 8.1 — The domain-to-integration bridge: a guarded wildcard listener on the in-process dispatcher catches every committed DomainEvent and republishes it through the resolved CommandEventPublisher.

When no `firefly/eda` `EventPublisher` is bound at all, `CqrsAutoConfiguration` falls back to `NoOpEventPublisher`, which drops the event (optionally logging at debug) — installing `firefly/cqrs` without `firefly/eda` still boots cleanly and simply emits no integration events.

**The gap this leaves open.** The write commits; *then*, in a separate step, the broker publish is attempted. Between those two steps there is a real window — a crashed process, a broker outage — in which the aggregate's own database state has moved forward but the integration event describing that move was never sent. This is an honest, at-least-once-*ish*, non-atomic guarantee, adequate for a great many applications and explicitly not adequate for money movements that other services must react to reliably. Closing that gap is exactly what the rest of this chapter builds.

---

## The genuine same-transaction outbox (`firefly/eda-database`)

`firefly/eda-database` closes the gap by writing the outbox row **inside** the aggregate's own open transaction, on the aggregate's own connection — so it commits or rolls back atomically with the aggregate, with no dual-write and nothing to "hope" about after the fact — and it does so on PostgreSQL, MySQL, MariaDB, SQL Server and SQLite alike. `firefly/eda-postgres` is the same outbox with two PostgreSQL optimizations, and the end of this section shows exactly what they are.

::: figure art/figures/outbox-flow.svg | Figure 8.2 — The same-transaction outbox: the aggregate's own commit and the outbox INSERT are one atomic unit; a consumer claims PENDING rows independently.

### Switching it on

The outbox is one setting and one table. `firefly.eda.provider=database` selects it; the reference configuration ships its block commented out at the defaults:

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

<!-- illustrative: the two commands a deployment runs once it selects provider=database -->
```bash
php artisan migrate               # creates firefly_eda_outbox (and adds the lease columns to an older one)
php artisan firefly:eda:consume   # the terminal in-process delivery — run one or more as long-lived workers
```

Nothing in Lumen's code changes. The sample's `OutboxDatabaseTestCase` adds `firefly/eda-database`'s provider and sets the key, and the same `OpenWallet` and `Deposit` commands now leave their events in the table until a consumer comes for them:

<!-- source: samples/lumen/tests/Outbox/OutboxDatabaseDeliveryTest.php -->
```php
    // Committed with the wallet and waiting for a consumer: the projector has not run yet.
    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited'])
        ->and(LedgerEntry::query()->where('wallet_id', $id)->count())->toBe(0);

    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 2, '--time-limit' => 10, '--poll-timeout' => 100]);
```

That is the trade the outbox makes, stated as two assertions: the events are durable the instant the wallet commits, and `LedgerProjector` runs a moment later, in a worker, instead of inside the request. The rest of this section is how both halves are guaranteed.

### The seam: `PreCommitEventHook`

Chapter 6 showed `DomainEventDispatcher::dispatchAfterCommit()` draining the `AggregateTracker` and scheduling each event via `DB::afterCommit()`. It carries one more, optional collaborator — a hook that runs **before** that scheduling, while the transaction is still open:

<!-- source: packages/data/src/Domain/PreCommitEventHook.php -->
```php
interface PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void;
}
```

With no hook bound — any provider other than `database` or `postgres` — this is a complete no-op and `DomainEventDispatcher` behaves exactly as Chapter 6 described. `firefly/eda-database`'s `OutboxPreCommitHook` is the implementation that turns it on, under both outbox providers:

<!-- source: packages/eda-database/src/Outbox/OutboxPreCommitHook.php -->
```php
class OutboxPreCommitHook implements PreCommitEventHook
{
    // …
    public function handle(object $event, ?string $connection = null): void
    {
        if (! $event instanceof DomainEvent) {
            return;
        }

        // The aggregate's connection — the one carrying the open transaction.
        $publisher = $this->writerFor($this->connections->connection($connection));

        (new EdaCommandEventPublisher($publisher, $this->defaultDestination, $this->destinations, $this->correlation))
            ->publish($event);
    }

    /** The writer for one event, on the aggregate's own connection. */
    protected function writerFor(ConnectionInterface $connection): DatabaseEventPublisher
    {
        return new DatabaseEventPublisher($connection, $this->registry, $this->channel, $this->tracing);
    }
}
```

`firefly/eda-postgres`'s hook of the same short name overrides only `writerFor()`, returning a `PostgresEventPublisher` that also notifies on a `pgsql` connection.

`$connection` is threaded through unchanged from the dispatcher, so a `#[Transactional(connection: 'x')]` aggregate's outbox row lands on connection `x` — never a hard-coded default — and the hook reuses the *exact same* `EdaCommandEventPublisher` mapping (event type, payload, destination, correlation id) the after-commit bridge uses, so the row this writes is byte-for-byte identical in shape to what the ordinary bridge would have produced.

### The INSERT, and `pg_notify` in the same transaction

<!-- source: packages/eda-database/src/DatabaseEventPublisher.php -->
```php
class DatabaseEventPublisher implements EventPublisher, OutboxWriter
{
    // …
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->tracing->tracePublish($destination, $eventType, $headers, function (array $headers) use ($destination, $eventType, $payload): void {
            $id = $this->connection->table(OutboxSchema::TABLE)->insertGetId([
                'destination' => $destination,
                'channel' => $this->channel,
                'event_type' => $eventType,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'headers' => json_encode($headers === [] ? new stdClass : $headers, JSON_THROW_ON_ERROR),
                'transaction_id' => $headers['x-correlation-id'] ?? null,
                'status' => OutboxSchema::STATUS_PENDING,
                'attempts' => 0,
                'created_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
            ]);

            $this->afterInsert($id);
        });
    }
// …
}
```

<!-- source: packages/eda-postgres/src/PostgresEventPublisher.php -->
```php
final class PostgresEventPublisher extends DatabaseEventPublisher
{
    // …
    protected function afterInsert(int $id): void
    {
        if ($this->emitNotify) {
            // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly when
            // the PENDING row becomes visible. (sqlite and every other driver: emitNotify=false.)
            $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
        }
    }
}
```

Because `publish()` runs on **whatever connection it was constructed with**, and that connection is mid-transaction when `OutboxPreCommitHook` calls it, the `INSERT` enlists in the aggregate's own transaction: it commits when the aggregate commits, and it rolls back when the aggregate rolls back. Under `firefly.eda.provider=postgres` the writer is `PostgresEventPublisher`, whose `afterInsert()` — called from the same closure — also fires `SELECT pg_notify(?, ?)` **inside the same transaction**; under `provider=database` there is no NOTIFY and the consumer polls. Postgres queues a `NOTIFY` raised inside a transaction and only actually delivers it once that transaction commits, so a listening consumer wakes with low latency at exactly the moment the row becomes visible to other connections — and never wakes for a row that gets rolled back.

The whole of that body sits inside `EdaTracing::tracePublish()` — the single port all three broker publishers now route through, `eda-rabbitmq` and `eda-kafka` alongside this one. Because propagation means *writing* something on the envelope, the port hands the send closure the headers to carry and the adapter builds the row out of those, so the `traceparent` the producer span stamps is on the row that commits with the aggregate — this is the one adapter where the producer span and the durable record are genuinely atomic, rather than a span that ends the moment a socket accepted the message. With tracing off the bound `EdaTracing` is `NoOpEdaTracing`, which calls the closure with the caller's headers unchanged, so the path is byte-for-byte what it was; and `transaction_id` is still read from `x-correlation-id`, never from a traceparent, so the relay's idempotency key does not change just because a request happened to be traced.

The outbox table (`firefly_eda_outbox`, one schema shared by the migration, the publisher, the consumer, and the relay) carries: `id, destination, channel, event_type, payload, headers, transaction_id, status, attempts, error_message, created_at, processed_at, failed_at, claimed_by, claimed_until` — the last two are the claim's lease — with `status` one of `PENDING` / `PUBLISHED` / `FAILED`.

A genuine, shipped test proves both halves of the guarantee directly against a real transaction:

<!-- source: packages/eda-postgres/tests/OutboxSameTransactionTest.php -->
```php
it('writes the outbox row INSIDE the caller transaction (present after commit)', function () {
    // …
    DB::transaction(function () use ($publisher): void {
        $publisher->publish('users', 'user.created', ['id' => 1], ['x-a' => 'b']);
    });
    // …
    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'user.created')
        ->where('status', OutboxSchema::STATUS_PENDING)
// …
});

it('rolls the outbox row back WITH the aggregate (absent after rollback)', function () {
    // …
    try {
        DB::transaction(function () use ($publisher): void {
            $publisher->publish('users', 'user.created', ['id' => 2]);
            throw new RuntimeException('business failure after the outbox write');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The INSERT rolled back atomically with the aggregate's tx: no row survives.
    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(0);
});
```

No amount of prose substitutes for that second test: the outbox row from the failed unit of work simply does not exist afterward, because it was never a separate write to begin with — it was one more statement inside the same transaction that rolled back.

### Double-publish avoidance

With `provider=postgres` bound, both `OutboxPreCommitHook` (in-tx) *and* the ordinary after-commit `DomainEventBridge` could, in principle, both try to write the same event to the outbox. `PostgresOutboxAutoConfiguration` prevents that at `#[Order(900)]` (and `DatabaseOutboxAutoConfiguration` does the same under `provider=database`) — ahead of `firefly/data`'s and `firefly/cqrs`'s `#[Order(1000)]` defaults — by binding, together, as one package:

- its **own** `DomainEventDispatcher`, carrying the `OutboxPreCommitHook`, which wins `DataAutoConfiguration`'s `#[ConditionalOnMissingBean(DomainEventDispatcher::class)]`, and
- a `NoOpEventPublisher` as the `CommandEventPublisher`, which wins `CqrsAutoConfiguration`'s `#[ConditionalOnMissingBean(CommandEventPublisher::class)]` — silencing **only** the after-commit eda leg.

The result: a domain event is written to the outbox **exactly once**, in-tx, atomically with the aggregate. The generic (non-eda) `#[AsEventListener]` after-commit dispatch this chapter opened with is completely untouched — those in-process listeners keep firing exactly as before. All four beans are additionally gated on their provider value (`#[ConditionalOnProperty(name: 'firefly.eda.provider', havingValue: 'postgres')]`, or `'database'`), so installing the package without selecting it as the active provider is fully inert.

### Claiming rows: `DatabaseEventConsumer`

Once a row is `PENDING`, `DatabaseEventConsumer` (from `firefly/eda-database`) is the always-available, terminal in-process delivery path — `php artisan firefly:eda:consume` drives it as a long-running worker, under `provider=database` and `provider=postgres` alike:

<!-- source: packages/eda-database/src/DatabaseEventConsumer.php -->
```php
class DatabaseEventConsumer implements EventConsumer
{
    // …
    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        $received = $this->claimOne();
        if ($received !== null) {
            return $received;
        }

        try {
            ($this->awaitWork)($timeoutMs);
        } catch (Throwable) {
            // The wait is an optimization; the claim below delivers regardless.
        }

        return $this->claimOne();
    }

    public function ack(ReceivedEnvelope $received): void
    {
        ClaimableRows::owned($this->connection, OutboxRow::asInt($received->deliveryTag), $this->claimant)->update([
            'status' => OutboxSchema::STATUS_PUBLISHED,
            'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP'),
            'claimed_by' => null,
            'claimed_until' => null,
        ]);
    }
// …
}
```

A claim is a **lease**: `claimOne()` asks the configured `OutboxClaimStrategy` for one row, and the strategy writes `claimed_by` and `claimed_until` on it and commits that *before* the handler runs. How the row is picked depends on what the server speaks — `FOR UPDATE SKIP LOCKED` inside a short claim transaction on PostgreSQL, MySQL 8.0.1+ and MariaDB 10.6+, the `UPDLOCK, READPAST` table hint on SQL Server, a conditional `UPDATE … WHERE status = 'PENDING' AND claimed_until < now` everywhere else — but every strategy ends with the row leased to exactly one consumer. `ack()` and `nack()` settle only a row this consumer still holds, so a consumer that outlived its lease settles nothing. Note there is **no `EventPublisher::publish()` call anywhere in this class**: it reads and updates the outbox table directly, so there is no re-`INSERT`. A handler throw calls `nack()`, which increments `attempts` and frees the row for an immediate retry or, past `firefly.eda.database.max-attempts` (default 3; `firefly.eda.postgres.max_attempts` is its alias), marks it `FAILED` in place — a `status='FAILED'` row *is* the outbox's dead letter.

Under `provider=postgres`, `PostgresEventConsumer` is this class plus Postgres's wake-up — `LISTEN` on subscribe, and a `NOTIFY` wait instead of a sleep between empty claims:

<!-- source: packages/eda-postgres/src/PostgresEventConsumer.php -->
```php
    public function subscribe(array $destinations): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->getPdo()->exec('LISTEN '.$this->channel);
        }
    }
```

This is a **durable** `PENDING`→`PUBLISHED` status window, not an in-memory high-water mark: a restarted consumer resumes at the oldest claimable row and never replays a `PUBLISHED` one. Any number of consumer workers may run at once, on any of the five databases — each row is delivered once while its consumer lives within its lease (`firefly.eda.database.lease-seconds`, 300 by default); a consumer that crashes mid-handler leaves its row to be claimed again when the lease ends, so across a crash delivery is at-least-once and listeners should still be idempotent.

### How a claim is chosen, server by server

`claimOne()` never hard-codes its SQL. An `OutboxClaimStrategy` does the picking, and `firefly.eda.database.claim-strategy=auto` (the default) chooses one from what the server reports about itself — `DatabaseCapabilities` in `firefly/data` reads the version, never the driver name, so a `mysql` connection whose server is MariaDB is recognized as MariaDB:

| Strategy | How it picks a row | `auto` chooses it on |
|---|---|---|
| `skip-locked` | `SELECT … FOR UPDATE SKIP LOCKED` in a short claim transaction (MySQL and MariaDB run it `READ COMMITTED`, so publishers never wait on its gap locks) | PostgreSQL; MySQL 8.0.1+; MariaDB 10.6+ |
| `read-past` | `SELECT TOP n … WITH (UPDLOCK, READPAST, ROWLOCK)` in a short claim transaction | SQL Server |
| `optimistic-lease` | an unlocked read, then one conditional `UPDATE … WHERE status = 'PENDING' AND (claimed_until IS NULL OR claimed_until < now)` per row; the row is won when exactly one row changed | SQLite; any server the probe does not recognise; older MySQL and MariaDB |

A strategy forced by name that the server cannot run fails at the first claim with an error naming the server and its version — it is never downgraded in silence — and the choice is made at that first claim, so booting an application opens no connection to find out. Lease instants are the application's clock in UTC at millisecond precision, so keep the nodes' clocks synchronized well within `firefly.eda.database.lease-seconds`.

### Any number of consumers

Because every strategy ends with the row leased to exactly one claimant, scaling delivery is starting another `firefly:eda:consume`. Each row is delivered once while its worker lives within its lease; a worker that dies mid-delivery leaves its row leased until the lease ends, and then another worker delivers it — across a crash, delivery is at least once. `packages/eda-database/tests/Integration/OutboxConcurrencyIntegrationTest.php` races two processes over one outbox on every server, and the sample runs its own round trip on each of them, one dataset entry per lane:

<!-- source: samples/lumen/tests/Integration/OutboxDatabaseRoundTripTest.php -->
```php
    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 2, '--time-limit' => 20, '--poll-timeout' => 500]);

    expect($exit)->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(0)
        ->and(LedgerEntry::query()->where('wallet_id', $id)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited']);
})->with(IntegrationDsn::lanes())->group('integration');
```

Under `provider=database` the actuator's health report gains an `outbox` component — the driver, the strategy in use and the `PENDING` backlog, or `DOWN` with the error — so a consumer fleet that has stopped keeping up is visible before anyone asks why the ledger is late.

### Forwarding to a broker: `firefly:outbox:relay`

`firefly:eda:consume` delivers rows to this application's own listeners. When other services must hear the events too, the relay forwards committed rows to a second broker and marks each one `PUBLISHED` — the same lease, the same `max-attempts`, the trace each row carries continued across the hop. `firefly.eda.database.relay.downstream-provider` names the broker (`rabbitmq`, `kafka`, an `EventPublisher` class-string or a container id), and the relay refuses the outbox writer itself as its downstream:

<!-- illustrative: an operator running the optional relay as its own worker -->
```bash
php artisan firefly:outbox:relay --batch-size=50 --sleep=1
```

### What `firefly/eda-postgres` adds

Everything above works on PostgreSQL as it is. `firefly.eda.provider=postgres` is that same outbox — the same table, the same claims, the same consumer — plus the two things only PostgreSQL offers: `PostgresEventPublisher` raises `pg_notify` inside the writing transaction, and `PostgresEventConsumer` `LISTEN`s and waits on the notification instead of sleeping `poll-interval-ms` between empty claims. Every `firefly.eda.postgres.*` key keeps working as an alias of its `firefly.eda.database.*` twin, and the database key wins when both are set, so moving between the two providers loses no setting.

!!! laravel "Laravel parity"
    Plain Laravel has no first-party transactional-outbox pattern at all — the idiom you'd hand-roll is an `outbox` table plus a scheduled command that polls it, with no framework help keeping the `INSERT` inside the same transaction as your domain write. `firefly/eda-database` is exactly that idiom, on any database Laravel speaks, but with the atomicity guaranteed by construction (`PreCommitEventHook` runs while the transaction is still open) rather than left to developer discipline.

---

## Dead letters that survive the process

The retry model at the start of this chapter ends with a dead letter, and by default that dead letter lives in `InMemoryDeadLetterStore` — gone when the process exits. That is fine for a test and wrong for a worker, so `firefly.eda.dead-letter.store` picks the store under every provider:

<!-- source: skeleton/config/firefly.php -->
```php
'dead-letter' => [
    'store' => env('FIREFLY_EDA_DEAD_LETTER_STORE', 'memory'),
    // 'connection' => 'mysql',
],
```

`database` writes one `firefly_eda_dead_letters` row per exhausted envelope, on `firefly.eda.dead-letter.connection` (the default connection when unset). Both stores implement `ReplayableDeadLetterStore`, which is what the replay command needs: it republishes entries, oldest first, through the bound `EventPublisher` to their original destination, and removes each one that republished:

<!-- illustrative: an operator inspecting, then replaying, the dead letters of one event type -->
```bash
php artisan firefly:eda:dead-letters:replay --event-type=FundsDeposited --dry-run
php artisan firefly:eda:dead-letters:replay --event-type=FundsDeposited --limit=100
```

`--id`, `--destination` and `--keep` narrow and preserve; `--dry-run` lists and changes nothing. `firefly/messaging` has the same pair — `firefly.messaging.dead-letter.store=memory|database` and `php artisan firefly:messaging:dead-letters:replay` — for its raw `MessageBrokerPort`. An outbox row that exhausts `max-attempts` is different: it is marked `FAILED` in place, because the row *is* the outbox's dead letter, and both proofs are on every server in the matrix (see the database support matrix in the documentation).

---

## Messaging on Kafka and RabbitMQ

`firefly/messaging` is the lower-level sibling of the event bus: raw `Message` payloads on a `MessageBrokerPort`, consumed by `#[MessageListener]` methods. `firefly.messaging.provider=kafka` (with `ext-rdkafka`) and `=rabbitmq` bind a listener container on the same client factories the event bus's adapters use, and one worker consumes from it:

<!-- illustrative: a messaging worker bounded by a message count and a time limit, as a supervisor would run it -->
```bash
php artisan firefly:messaging:consume --max-messages=1000 --time-limit=3600
```

The container owns retries: a delivery is attempted `firefly.messaging.listener.retry.max-attempts` times (five by default) with exponential back-off from one second, doubling, capped at thirty; a listener with `retries: N` gets N + 1 attempts with its own linear `retryDelay` instead. Then a dead letter — Kafka produces the record to the listener's `deadLetterTopic` or `<topic>.DLT`, RabbitMQ republishes it to `deadLetterTopic` or lets the queue's dead-letter exchange move it to `<queue>.dlq` — carrying `x-original-topic`, `x-exception`, `x-exception-message` and `x-delivery-attempts`. A record is committed, or a message acknowledged, only after its listeners handled it or it was dead-lettered, so delivery is at least once; consumer groups are real (one Kafka consumer group per listener group, one durable RabbitMQ queue per group). On `memory` and `queue` the command refuses with exit code 1, because those providers have no consumer loop.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| Two event surfaces | `#[AsEventListener]` (in-process, `firefly/context`) vs. `#[EventListener]` (broker bus, `firefly/eda`) — unrelated mechanisms |
| `EventPublisher` / `EventEnvelope` | The broker-bus port; `destination` is *where*, `eventType` is *what fact* |
| `SubscriberRegistry::deliver()` | Matches `fnmatch($pattern, $envelope->eventType)` — **never** the destination; the exact gotcha `LedgerProjector` hit |
| `RetryingEventHandler` | Always wraps every listener: linear-backoff retry, then dead-letter (or re-throw with no store bound) |
| `InMemoryEventBus` / `QueueEventBus` | Synchronous vs. queue-backed (fire-and-forget) `EventPublisher` adapters, identical port |
| `DomainEventBridge` | The after-commit trigger republishing a committed `DomainEvent` as an integration event — best-effort, non-atomic |
| `OutboxPreCommitHook` / `DatabaseEventPublisher` | Writes the outbox row **inside** the aggregate's own open transaction — genuinely atomic, on any of the five databases; `PostgresEventPublisher` adds `pg_notify` |
| `DatabaseEventConsumer` / `PostgresEventConsumer` | Leases `PENDING` rows (SKIP LOCKED, READPAST or a conditional UPDATE — any number of workers), drives `#[EventListener]` handlers directly, no re-`INSERT`; Postgres adds `LISTEN`/`NOTIFY` |
| `OutboxClaimStrategy` | `skip-locked`, `read-past` or `optimistic-lease`, chosen by `auto` from the server's version; every one leases a row to exactly one claimant |
| `firefly:outbox:relay` | Optional: forwards committed rows to a second broker (`firefly.eda.database.relay.downstream-provider`) |
| `firefly.eda.dead-letter.store=database` | Dead letters in `firefly_eda_dead_letters`, replayed by `firefly:eda:dead-letters:replay` (messaging has the same pair) |
| `firefly:messaging:consume` | The Kafka/RabbitMQ listener-container worker: container retries, dead-letter topics or queues, at-least-once commits |

---

## Try it yourself {.exercises}

1. **Reproduce the gotcha, deliberately.** In a scratch copy of the project, change `LedgerProjector`'s `#[EventListener]` patterns to `['wallet.*']` and re-run its test suite. Confirm the projector silently never fires and no `ledger_entries` rows are ever written — then restore the original type-name patterns and confirm they pass again.
2. **Force a dead letter.** Write a small `#[EventListener]` handler that always throws, configure `firefly.eda.retries=2` and bind an `InMemoryDeadLetterStore`, publish a matching event, and inspect `DeadLetterStore::all()` afterward. Confirm the entry's `exceptionMessage` matches what your handler threw.
3. **Prove the outbox on a real server.** Export one lane's DSN (Chapter 12 shows how) and run `composer test:integration -- samples/lumen/tests/Integration/OutboxDatabaseRoundTripTest.php`. Confirm the two rows are `PENDING` before the consumer runs and `PUBLISHED` after it, then read `OutboxSameTransactionTest` for the rollback half: the row from a failed unit of work never exists at all.
