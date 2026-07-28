<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 8</span>

# Event-Driven Architecture and the Transactional Outbox {.chtitle}

By the end of this chapter you will know the two, deliberately distinct event surfaces LaraFly ships and why confusing them is the single most common mistake; the `EventPublisher` port and the `EventEnvelope` it carries; how `#[EventListener]` matches an event's **type name** — not its destination — a real gotcha the `samples/lumen` build hit while wiring `LedgerProjector` in Chapter 6; the in-memory and queue adapters; the retry/dead-letter model every listener is wrapped in; and — closing the durability gap the domain→integration bridge leaves open — the genuine **same-transaction outbox** `firefly/eda-postgres` writes atomically with the aggregate that raised the event.

!!! note "New term: integration event"
    Chapter 6 built **domain events** — `WalletOpened`, `FundsDeposited` — facts an aggregate raises for consumers inside the *same* process and the *same* deployable. An **integration event** is the same fact re-emitted across a process or service boundary: a JSON-ish envelope with a type name, a payload, and routing headers, carried by a broker (or, in-process, by the very same bus). This chapter is about the machinery on the far side of that boundary, and the bridge that gets a domain event onto it.

---

## Two event surfaces — not one

LaraFly ships two unrelated event mechanisms that happen to share a similar-looking attribute name:

| Surface | Attribute | Subscribes to | Delivery | Package |
|---|---|---|---|---|
| In-process application events | `#[AsEventListener]` | a PHP event **class** | synchronous, same process, no broker | `firefly/context` |
| EDA broker bus | `#[EventListener]` | an event-type **pattern** (fnmatch glob, e.g. `'user.*'`) | in-memory (sync) or queue (async) | `firefly/eda` |

`#[AsEventListener]` fires when application code calls `ApplicationEventPublisher::publish(object $event)` with a typed PHP object — the seam Chapter 6's after-commit dispatch itself rides on (`DomainEventDispatcher` calls exactly this to publish a drained `DomainEvent`). `#[EventListener]`, this chapter's subject, fires when an `EventEnvelope` whose `eventType` string matches your pattern arrives on the **eda** bus — in-memory, via a queue worker, or (this chapter's second half) via a Postgres outbox row. Neither package depends on the other; nothing in `firefly/eda` even imports Illuminate's `Dispatcher`.

---

## The `EventPublisher` port and the `EventEnvelope`

Everything in this chapter routes through one small port:

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

`eventType` and `destination` are two genuinely different things, and the rest of this chapter hinges on that distinction: `destination` is *where* the envelope is routed on the wire (a queue name, a Postgres outbox row's `destination` column); `eventType` is *what fact it carries*, and it is the **only** field a `#[EventListener]` pattern is ever matched against.

`withHeaders(array $extra): self` returns a copy with merged headers — used internally to stamp `x-correlation-id`, and (you'll meet this again below) `x-original-topic`/`x-exception` on a dead-lettered envelope. `start()`/`stop()` are no-ops on the in-memory and queue adapters; the outbox's own consumer (later in this chapter) is where they start doing real work.

---

## `#[EventListener]` and the eventType-match gotcha

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class EventListener
{
    /** @var list<string> */
    public readonly array $patterns;

    /**
     * @param  string|array<int, string>  $patterns
     */
    public function __construct(string|array $patterns = [], public readonly int $order = 0)
    {
        $this->patterns = is_string($patterns) ? [$patterns] : array_values($patterns);
    }
}
```

`#[EventListener]` is **inert metadata only**, same rule as every Firefly attribute — a single string pattern normalises to a one-element list, and `IS_REPEATABLE` lets one method carry several separately-ordered subscriptions. The subscription itself happens inside the shared registry both shipped adapters compose:

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

```php
#[Component]
final class LedgerProjector
{
    /**
     * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
     * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
     * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
     * would therefore never match any wallet event and this projector would silently never fire.
     */
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // ...
    }
}
```

A tempting-looking `#[EventListener(['wallet.*'])]` — reasoning by analogy from the destination string — compiles, deploys, and then never once invokes `onWalletEvent()`, because no wallet event's `eventType` (`'FundsDeposited'`, `'WalletOpened'`, …) ever begins with `'wallet.'`; that word only ever appears in the *destination*. Chapter 6 showed you `LedgerProjector`'s full source and its consequences for the read model; this chapter is where the rule it depends on actually lives.

!!! warning "`eventType`, never `destination`"
    Whenever you write a `#[EventListener]` pattern, ask "does this glob match the short class name of the event I want, not the queue/topic string it happens to route through?" The two are unrelated strings that are easy to conflate precisely because they often *look* related (`'wallet.events'` destination, `'WalletOpened'` event type) — `fnmatch()` only ever sees the second one.

A second, minimal illustration from `firefly/eda`'s own documentation makes the call site concrete, pattern and imperative publish together:

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

Here the destination (`'firefly.events'`) and the event type (`'order.placed'`) are deliberately different strings, and the `'order.*'` pattern matches the **second** one — precisely so this example cannot be mistaken for evidence that patterns ever look at the destination.

`EventListenerScanner` (`packages/eda/src/Scanner/EventListenerScanner.php`) is the sole reflection site in `firefly/eda`, walking the app's PSR-4 roots once and compiling every `#[EventListener]` method into an `EventListenerDescriptor`; `EventListenerManifest` loads the result with zero reflection, exactly the `HandlerManifest` idiom Chapter 7 just showed you. `firefly:cache` runs this scanner as one of its twelve pairs, writing `bootstrap/cache/firefly/event-listeners.php`; `FireflyCacheServiceProvider` binds it, overriding the empty default an uncached app would otherwise boot with. `EventListenerWiringPass` then walks that manifest at boot — in *every* process, web request and queue worker alike — wraps each target invocation in the retry/DLQ decorator below, and calls `$bus->subscribe($pattern, $wrapped)` per pattern, resolving the target bean **fresh from the container on every dispatch**.

---

## Retry and dead-letter: every listener is wrapped, always

`RetryingEventHandler::wrap()` decorates *every* subscribed handler — there is deliberately no unwrapped fast path:

```php
final class RetryingEventHandler
{
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

```php
final class InMemoryEventBus implements EventPublisher
{
    public function __construct(private readonly SubscriberRegistry $registry) {}

    public function subscribe(string $eventTypePattern, callable $handler): void
    {
        $this->registry->subscribe($eventTypePattern, $handler);
    }

    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $this->registry->deliver(new EventEnvelope($eventType, $destination, $payload, $headers));
    }

    public function start(): void {}

    public function stop(): void {}
}
```

`publish()` builds the envelope and calls `deliver()` **synchronously** — every matching handler has already run by the time `publish()` returns. `QueueEventBus` (`firefly.eda.provider=queue`) keeps the identical `EventPublisher` contract but makes `publish()` fire-and-forget:

```php
final class QueueEventBus implements EventPublisher
{
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
        $job = (new DispatchEventJob(new EventEnvelope($eventType, $destination, $payload, $headers)))
            ->onConnection($this->connection)
            ->onQueue($this->queue);

        // Resolved EVERY call so Bus::fake() intercepts — never hoist into the constructor.
        $this->container->make(Dispatcher::class)->dispatch($job);
    }

    public function deliver(EventEnvelope $envelope): void
    {
        $this->registry->deliver($envelope);
    }
}
```

`publish()` returns before any listener has run — delivery happens on whichever queue worker picks up the `DispatchEventJob`. That worker's own boot repopulates its `SubscriberRegistry` from the same compiled manifest `EventListenerWiringPass` reads everywhere, so a share-nothing worker reconstructs the identical subscriber set every time it starts. Under the `sync` queue driver, delivery collapses to the same synchronous behaviour as `InMemoryEventBus` — which is exactly how a test exercises the async path with no worker actually running.

---

## The domain → integration-event bridge

Chapter 6 showed you the *effect* of the bridge — a committed `WalletOpened` reaching `LedgerProjector` as an `EventEnvelope`. Here is the mechanism. Every `DomainEvent` committed through `firefly/data`'s after-commit dispatch (Chapter 6's closing section) is also published, in-process, to `ApplicationEventPublisher` — the very `#[AsEventListener]` surface this chapter opened by contrasting with `#[EventListener]`. Because Illuminate's own dispatcher matches an object event only by its **concrete class** (plus interfaces, never parent classes), a single `#[AsEventListener]` typed on the abstract `DomainEvent` base could never fire for any concrete subclass. `DomainEventBridgeWiringPass` works around exactly this limitation with one guarded wildcard listener instead:

```php
final class DomainEventBridgeWiringPass implements BootPass
{
    public function run(BootContext $context): void
    {
        $container = $context->container;
        $dispatcher = $container->make('events');

        $dispatcher->listen('*', DispatcherEventPublisher::guardListener(
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

```php
final class DomainEventBridge
{
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

## The genuine same-transaction outbox (`firefly/eda-postgres`)

`firefly/eda-postgres` closes the gap by writing the outbox row **inside** the aggregate's own open transaction, on the aggregate's own connection — so it commits or rolls back atomically with the aggregate, with no dual-write and nothing to "hope" about after the fact.

::: figure art/figures/outbox-flow.svg | Figure 8.2 — The same-transaction outbox: the aggregate's own commit and the outbox INSERT are one atomic unit; a consumer claims PENDING rows independently.

### The seam: `PreCommitEventHook`

Chapter 6 showed `DomainEventDispatcher::dispatchAfterCommit()` draining the `AggregateTracker` and scheduling each event via `DB::afterCommit()`. It carries one more, optional collaborator — a hook that runs **before** that scheduling, while the transaction is still open:

```php
interface PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void;
}

final class DomainEventDispatcher
{
    public function __construct(
        private readonly AggregateTracker $tracker,
        private readonly ApplicationEventPublisher $publisher,
        private readonly ?PreCommitEventHook $preCommitHook = null,
    ) {}

    private function afterCommit(object $event, ?string $connection): void
    {
        // SP-4 same-tx seam: when bound, write the event to the outbox WITHIN the still-open tx (this method runs
        // during TransactionTemplate's pre-commit drain), so it commits/rolls back atomically with the aggregate.
        $this->preCommitHook?->handle($event, $connection);

        DB::connection($connection)->afterCommit(function () use ($event): void {
            $this->publisher->publish($event);
        });
    }
}
```

With no hook bound — no `firefly/eda-postgres`, or any provider other than `postgres` — this is a complete no-op and `DomainEventDispatcher` behaves exactly as Chapter 6 described. `firefly/eda-postgres`'s `OutboxPreCommitHook` is the implementation that turns it on:

```php
final class OutboxPreCommitHook implements PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void
    {
        if (! $event instanceof DomainEvent) {
            return;
        }

        $conn = $this->connections->connection($connection); // the aggregate's OWN connection — carries the open tx
        $emitNotify = $conn instanceof Connection && $conn->getDriverName() === 'pgsql';
        $publisher = new PostgresEventPublisher($conn, $this->channel, $emitNotify);

        (new EdaCommandEventPublisher($publisher, $this->defaultDestination, $this->destinations, $this->correlation))
            ->publish($event);
    }
}
```

`$connection` is threaded through unchanged from the dispatcher, so a `#[Transactional(connection: 'x')]` aggregate's outbox row lands on connection `x` — never a hard-coded default — and the hook reuses the *exact same* `EdaCommandEventPublisher` mapping (event type, payload, destination, correlation id) the after-commit bridge uses, so the row this writes is byte-for-byte identical in shape to what the ordinary bridge would have produced.

### The INSERT, and `pg_notify` in the same transaction

```php
final class PostgresEventPublisher implements EventPublisher
{
    public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
    {
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

        if ($this->emitNotify) {
            // In-tx pg_notify: Postgres queues it and delivers on COMMIT, so the consumer's LISTEN wakes exactly
            // when the PENDING row becomes visible. (sqlite: emitNotify=false.)
            $this->connection->statement('SELECT pg_notify(?, ?)', [$this->channel, (string) $id]);
        }
    }
}
```

Because `publish()` runs on **whatever connection it was constructed with**, and that connection is mid-transaction when `OutboxPreCommitHook` calls it, the `INSERT` enlists in the aggregate's own transaction: it commits when the aggregate commits, and it rolls back when the aggregate rolls back. When the driver is `pgsql`, the same statement also fires `SELECT pg_notify(?, ?)` — **inside the same transaction**. Postgres queues a `NOTIFY` raised inside a transaction and only actually delivers it once that transaction commits, so a listening consumer wakes with low latency at exactly the moment the row becomes visible to other connections — and never wakes for a row that gets rolled back.

The outbox table (`firefly_eda_outbox`, one schema shared by the migration, the publisher, the consumer, and the relay) carries: `id, destination, channel, event_type, payload, headers, transaction_id, status, attempts, error_message, created_at, processed_at, failed_at`, with `status` one of `PENDING` / `PUBLISHED` / `FAILED`.

A genuine, shipped test proves both halves of the guarantee directly against a real transaction:

```php
it('writes the outbox row INSIDE the caller transaction (present after commit)', function () {
    $publisher = new PostgresEventPublisher(DB::connection(), 'firefly_eda_events');

    DB::transaction(function () use ($publisher): void {
        $publisher->publish('users', 'user.created', ['id' => 1], ['x-a' => 'b']);
    });

    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'user.created')
        ->where('status', OutboxSchema::STATUS_PENDING)
        ->count())->toBe(1);
});

it('rolls the outbox row back WITH the aggregate (absent after rollback)', function () {
    $publisher = new PostgresEventPublisher(DB::connection(), 'firefly_eda_events');

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

With `provider=postgres` bound, both `OutboxPreCommitHook` (in-tx) *and* the ordinary after-commit `DomainEventBridge` could, in principle, both try to write the same event to the outbox. `PostgresOutboxAutoConfiguration` prevents that at `#[Order(900)]` — ahead of `firefly/data`'s and `firefly/cqrs`'s `#[Order(1000)]` defaults — by binding, together, as one package:

- its **own** `DomainEventDispatcher`, carrying the `OutboxPreCommitHook`, which wins `DataAutoConfiguration`'s `#[ConditionalOnMissingBean(DomainEventDispatcher::class)]`, and
- a `NoOpEventPublisher` as the `CommandEventPublisher`, which wins `CqrsAutoConfiguration`'s `#[ConditionalOnMissingBean(CommandEventPublisher::class)]` — silencing **only** the after-commit eda leg.

The result: a domain event is written to the outbox **exactly once**, in-tx, atomically with the aggregate. The generic (non-eda) `#[AsEventListener]` after-commit dispatch this chapter opened with is completely untouched — those in-process listeners keep firing exactly as before. All four beans are additionally gated `#[ConditionalOnProperty('firefly.eda.provider', 'postgres')]`, so installing the package without selecting it as the active provider is fully inert.

### Claiming rows: `PostgresEventConsumer`

Once a row is `PENDING`, `PostgresEventConsumer` is the always-available, terminal in-process delivery path — `php artisan firefly:eda:consume` drives it as a long-running worker:

```php
final class PostgresEventConsumer implements EventConsumer
{
    public function subscribe(array $destinations): void
    {
        if ($this->connection->getDriverName() === 'pgsql') {
            $this->connection->getPdo()->exec('LISTEN '.$this->channel);
        }
    }

    public function poll(int $timeoutMs): ?ReceivedEnvelope
    {
        try {
            ($this->awaitNotification)($timeoutMs);
        } catch (\Throwable) {
            // NOTIFY is a low-latency optimization; on ANY failure, fall through to the durable claim below.
        }

        $pgsql = $this->connection->getDriverName() === 'pgsql';
        $query = $this->connection->table(OutboxSchema::TABLE)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->orderBy('id');
        if ($pgsql) {
            $query->lock('for update skip locked'); // claim — Laravel has no skipLocked() helper
        }
        $row = $query->first();

        if ($row === null) {
            return null;
        }

        return new ReceivedEnvelope(new EventEnvelope(
            OutboxRow::asString($row->event_type),
            OutboxRow::asString($row->destination),
            OutboxRow::asMap($row->payload),
            OutboxRow::asStringMap($row->headers),
        ), OutboxRow::asInt($row->id));
    }

    public function ack(ReceivedEnvelope $received): void
    {
        $this->connection->table(OutboxSchema::TABLE)
            ->where('id', $received->deliveryTag)
            ->where('status', OutboxSchema::STATUS_PENDING)
            ->update(['status' => OutboxSchema::STATUS_PUBLISHED, 'processed_at' => $this->connection->raw('CURRENT_TIMESTAMP')]);
    }
}
```

`poll()` first waits briefly (best-effort, guarded against any failure) on a `NOTIFY`, then — regardless of whether one arrived — claims the oldest `PENDING` row with `FOR UPDATE SKIP LOCKED` on pgsql (a plain `ORDER BY id` under sqlite, for tests). Note there is **no `EventPublisher::publish()` call anywhere in this class** — it reads and updates the outbox table directly, so there is no re-`INSERT` and no risk of a consumer feeding its own output back into the table it just read from. `ConsumerLoop` delivers the claimed envelope to the same `SubscriberRegistry` your `#[EventListener]` handlers already subscribed to, exactly as the in-memory and queue adapters do; `ack()`'s `WHERE status = 'PENDING'` guard makes acknowledgement idempotent, and a handler throw instead calls `nack()`, which increments `attempts` and either resets the row to `PENDING` (retry) or, past `firefly.eda.postgres.max_attempts` (default 3), marks it `FAILED` in place — there is no separate dead-letter table for Postgres; a `status='FAILED'` row *is* the dead letter, queryable on the same `firefly_eda_outbox` table.

This is a **durable** `PENDING`→`PUBLISHED` status window, not an in-memory high-water mark: a restarted consumer resumes at the oldest still-`PENDING` row and never replays a `PUBLISHED` one. A single consumer worker gives exactly-once in-process delivery; running more than one concurrently degrades to at-least-once (write your listeners idempotently if you do).

!!! laravel "Laravel parity"
    Plain Laravel has no first-party transactional-outbox pattern at all — the idiom you'd hand-roll is an `outbox` table plus a scheduled command that polls it, with no framework help keeping the `INSERT` inside the same transaction as your domain write. `firefly/eda-postgres` is exactly that idiom, but with the atomicity guaranteed by construction (`PreCommitEventHook` runs while the transaction is still open) rather than left to developer discipline.

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
| `OutboxPreCommitHook` / `PostgresEventPublisher` | Writes the outbox row **inside** the aggregate's own open transaction — genuinely atomic |
| `PostgresEventConsumer` | Claims `PENDING` rows (`FOR UPDATE SKIP LOCKED` + `LISTEN`/`NOTIFY`), drives `#[EventListener]` handlers directly, no re-`INSERT` |

---

## Try it yourself {.exercises}

1. **Reproduce the gotcha, deliberately.** In a scratch copy of the project, change `LedgerProjector`'s `#[EventListener]` patterns to `['wallet.*']` and re-run its test suite. Confirm the projector silently never fires and no `ledger_entries` rows are ever written — then restore the original type-name patterns and confirm they pass again.
2. **Force a dead letter.** Write a small `#[EventListener]` handler that always throws, configure `firefly.eda.retries=2` and bind an `InMemoryDeadLetterStore`, publish a matching event, and inspect `DeadLetterStore::all()` afterward. Confirm the entry's `exceptionMessage` matches what your handler threw.
3. **Prove the outbox rollback yourself.** Using a Postgres connection (`@group('integration')`, per the framework's own test conventions), reproduce the two `OutboxSameTransactionTest` cases this chapter quoted — publish-then-commit and publish-then-throw — and confirm the row's presence or absence matches this chapter's description.
