# Event Sourcing

`firefly/eventsourcing` stores what happened instead of what is. An aggregate's state is the replay of its
events. The events live in an `EventStore` (`memory` or `database`, `firefly.eventsourcing.store.provider`),
appended under optimistic concurrency and read back two ways:

- as the aggregate's own stream;
- as one global stream in commit order, which projections follow with a checkpoint.

Snapshots shorten replays, upcasters move old event versions forward on read, and appended events can be
published to `firefly/eda` in the same transaction through the database outbox. The package is shaped after
the Java `fireflyframework-eventsourcing` library and PyFly's `pyfly.eventsourcing`.

## Choosing a store

`memory` (the default) keeps events in the process: right for tests and for trying the model out. `database`
keeps them in four portable tables on any Laravel connection, proven on PostgreSQL 16, MySQL 8.4, MariaDB 11,
SQL Server 2022 and SQLite (see the [database support matrix](../database-support.md)):

<!-- source: skeleton/config/firefly.php -->
```php
        'store' => [
            'provider' => env('FIREFLY_EVENTSOURCING_STORE', 'memory'),
            'connection' => env('FIREFLY_EVENTSOURCING_DB_CONNECTION'),
            'batch-size' => 500,
        ],
```

The snapshot store and the checkpoint store follow `store.provider` until you name them, so one variable moves
the whole package onto the database:

<!-- illustrative: an application's own .env file -->
```dotenv
FIREFLY_EVENTSOURCING_STORE=database
```

The tables are `firefly_eventsourcing_events`, `firefly_eventsourcing_head`, `firefly_eventsourcing_snapshots`
and `firefly_eventsourcing_checkpoints`. The package loads its migration while any of its stores is `database`;
publish it to keep it with your own:

<!-- illustrative: commands an operator runs in the application's directory -->
```bash
php artisan vendor:publish --tag=firefly-eventsourcing-migrations
php artisan migrate
```

## Events and the type registry

An event is a `firefly/domain` `DomainEvent` with a stable type name and a version, given by `#[EventType]`.
`php artisan firefly:cache` compiles every `#[EventType]` class under `firefly.scan.paths` into the
`eventsourcing.php` manifest, so production maps events without reflection. An event class that is not in the
registry is refused at append: nothing is ever stored that cannot be read back.

The convention mapper stores an event's public properties and rebuilds it with named constructor arguments,
coercing each value to the parameter's type (ints, floats, strings, bools, arrays, dates and backed enums).
Forward `eventId` and `occurredAt` to the parent so a reloaded event keeps its identity:

<!-- source: packages/eventsourcing/tests/Fixtures/Events/OrderPlaced.php -->
```php
#[EventType(name: 'order.placed', version: 2)]
final readonly class OrderPlaced extends DomainEvent
{
    /**
     * @param  list<string>  $skus
     */
    public function __construct(
        public string $orderId,
        public float $total,
        public OrderChannel $channel = OrderChannel::Web,
        public ?DateTimeImmutable $promisedBy = null,
        public array $skus = [],
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
```

An event whose stored payload should use names of its own, or hold a value object the convention cannot build,
implements `SerializableEvent` (`toPayload()` and a static `fromPayload()`):

<!-- source: packages/eventsourcing/tests/Fixtures/Events/OrderPaid.php -->
```php
    /** @return array{order: string, amount: int} */
    public function toPayload(): array
    {
        return ['order' => $this->orderId, 'amount' => $this->cents];
    }
```

Every event is stored as a `firefly/eda` `EventEnvelope`, serialized by the bound eda `Serializer`, with its
metadata: the correlation id of the request (the `firefly.correlation_id` Laravel `Context` key the web
package's correlation filter writes), the causation id, the tenant, the authenticated user's id
(`metadata.capture-user`) and the time it occurred. Add headers of your own with an `EventMetadataContributor`
component.

## Aggregates

Extend `EventSourcedAggregateRoot`. A command method validates against the current state and calls
`recordThat()`; the event is applied at once and buffered until the repository saves it. Applying calls a
protected `apply<EventShortName>()` method by convention (override `eventHandlers()` for other names):

<!-- source: packages/eventsourcing/tests/Fixtures/Order.php -->
```php
    public static function place(string $id, float $total): self
    {
        $order = new self($id);
        $order->recordThat(new OrderPlaced($id, $total));

        return $order;
    }

    public function pay(int $cents): void
    {
        if ($this->isPaid()) {
            throw new LogicException('The order is already paid.');
        }
        $this->recordThat(new OrderPaid($this->id(), $cents));
    }
    // …
    protected function applyOrderPlaced(OrderPlaced $event): void
    {
        $this->total = $event->total;
    }

    protected function applyOrderPaid(OrderPaid $event): void
    {
        $this->paidCents += $event->cents;
    }
```

Override `aggregateType()` with a stable name: it is stored with every event, and an id belongs to one
aggregate type only.

## The repository

A typed repository is a one-line constructor over the `EventSourcingSupport` bean:

<!-- source: packages/eventsourcing/tests/CapstoneFixtures/OrderRepository.php -->
```php
#[Component]
final class OrderRepository extends EventSourcedRepository
{
    public function __construct(EventSourcingSupport $support)
    {
        parent::__construct($support, Order::class);
    }
}
```

`find()` returns null for an id with no events, `get()` throws a 404 `AggregateNotFoundException`, and
`save()` appends the buffered events under the version the aggregate was loaded at. A writer that loaded the
same version earlier has already moved the stream, so the second save gets a 409 `ConcurrencyException`:

<!-- illustrative: an application's own command handler -->
```php
<?php

declare(strict_types=1);

namespace App\Orders;

use Firefly\EventSourcing\Exception\ConcurrencyException;

final class PayOrder
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function __invoke(string $orderId, int $cents): void
    {
        $order = $this->orders->get($orderId);
        $order->pay($cents);

        try {
            $this->orders->save($order);
        } catch (ConcurrencyException $stale) {
            // Another writer saved first: reload and decide again, or let the 409 reach the client.
            throw $stale;
        }
    }
}
```

A save inside your own transaction commits or rolls back with it. The repository drains the aggregate's
buffer, so these events are never dispatched a second time by `firefly/data`'s after-commit wiring.

## Snapshots

An aggregate that implements `SnapshotAware` (`snapshotState()`, `restoreSnapshot()`, `snapshotVersion()`) is
snapshotted each time a save moves its version across a multiple of `snapshot.threshold` (50 by default; 0
turns snapshots off), and the newest `snapshot.keep-count` (3) are kept. Loading starts from the newest snapshot
whose `snapshotVersion()` matches the class, so changing the snapshot shape is a version bump, never a migration.
A snapshot that cannot be written is logged and the save stands: snapshots are an optimization, the events are
the truth.

## The global stream and its visibility guarantee

Every committed event gets a global position, and `readAll($afterPosition)` returns events in position order.
The hard part is the order. A database sequence or identity hands out its value when a row is inserted, not
when its transaction commits, so a transaction holding position 10 can commit after the one holding 11. A
reader that read 11, checkpointed it, and only then saw 10 appear would skip 10 forever, and that happens on
every server LaraFly speaks.

The store therefore numbers events in COMMIT order. An event is inserted without a position; it is on its
aggregate's stream at once, but not yet on the global stream. Before a global read, the reader numbers the
committed, unnumbered events in a short transaction of its own, serialized on the `global` row of
`firefly_eventsourcing_head`: it locks that row, gives the next positions to committed rows in insert order
(READ COMMITTED on MySQL and MariaDB, `READPAST` on SQL Server, so an open append is neither seen nor waited
for), and moves the head. A position is only ever given above every position given before, so **a reader
never sees an event appear below a position it has already read**. An aggregate's events keep their version
order on the global stream, and appends never wait on each other except on the same aggregate and version.

Two consequences:

- positions are contiguous today, but read them as gap-tolerant;
- a `readAll()` inside an open transaction does not number (the head lock is never held for your unit of work),
  so it returns only what was numbered before.

## Projections

A projection is a read model kept from the global stream: `name()`, `handles()` (the event types it wants,
`[]` for all), `handle()` and `reset()`. Make it a component and the runner finds it:

<!-- source: packages/eventsourcing/tests/CapstoneFixtures/OrderTotalsProjection.php -->
```php
    public function name(): string
    {
        return 'order.totals';
    }

    public function handles(): array
    {
        return ['order.placed', 'order.paid'];
    }
```

Or register a closure-based `FunctionProjection` on the `ProjectionRegistry` bean:

<!-- illustrative: an application's own service provider -->
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\FunctionProjection;
use Firefly\EventSourcing\Projection\ProjectionRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class ProjectionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->afterResolving(ProjectionRegistry::class, static function (ProjectionRegistry $projections): void {
            $projections->register(new FunctionProjection(
                'orders.placed-count',
                static function (StoredEvent $event): void {
                    Cache::increment('orders.placed');
                },
                ['order.placed'],
                static function (): void {
                    Cache::forget('orders.placed');
                },
            ));
        });
    }
}
```

The `ProjectionRunner` advances a projection one batch (`projection.batch-size` events) at a time:

1. It takes the projection's lease on the `firefly/scheduling` `DistributedLock`
   (`firefly.scheduling.lock.provider`), for `projection.lease-ttl`. Another node holding it means this node
   leaves the projection alone.
2. It reads the checkpoint and the events after it.
3. In one transaction on the checkpoint store's connection it hands the projection the events it handles and
   advances the checkpoint from the position it read. That advance is fenced: when a runner whose lease had
   expired moved first, it fails and the whole batch rolls back.
4. It releases the lease. The lease is taken per batch, so keep a batch well under `lease-ttl`.

A projection that writes on the checkpoint connection (the `store.connection`) is therefore exactly-once: its
rows and its checkpoint commit together. Anything else it touches is at-least-once, so make those handlers
idempotent. A projection that throws rolls its batch back; the error is recorded on its checkpoint and the next
cycle retries it.

<!-- source: skeleton/config/firefly.php -->
```php
        'projection' => [
            'checkpoint-store' => env('FIREFLY_EVENTSOURCING_CHECKPOINT_STORE'),
            'batch-size' => 100,
            'lease-ttl' => '30s',
            'poll-interval' => '1s',
            'max-lag' => 0,
        ],
```

An `InlineProjection` is one its writer keeps current in the same transaction as the events (the orchestration
read model below is one); the runner only ever replays it in a rebuild.

## Operating projections

<!-- illustrative: commands an operator runs in the application's directory -->
```bash
php artisan firefly:eventsourcing:projections
php artisan firefly:eventsourcing:projections:run
php artisan firefly:eventsourcing:projections:run --live
php artisan firefly:eventsourcing:projections:rebuild order.totals
```

- `firefly:eventsourcing:projections` lists every projection with its mode (`catch-up` or `inline`), its
  checkpoint, the global head, its lag and its last error.
- `firefly:eventsourcing:projections:run` catches one projection (or every catch-up projection) up and exits;
  `--live` keeps following the stream, sleeping `poll-interval` when idle, until SIGINT/SIGTERM or
  `--max-runtime` seconds. Run it under a process supervisor on as many nodes as you like: the lease lets one
  node advance each projection at a time.
- `firefly:eventsourcing:projections:rebuild <name>` resets the projection and its checkpoint under its lease
  and replays the whole stream into it.

The `eventsourcing` health component (`health.enabled`) is DOWN when the event store does not answer, or when a
catch-up projection lags the head by more than `projection.max-lag` events (0 never trips it). Its details hold
the head and every projection's position, lag and state; a failed projection shows its error there, and its
growing lag is what trips the threshold.

## Upcasting

Events are immutable, so a changed event shape is a new version plus an `EventUpcaster` that moves the old
version forward ON READ, in the store, so repositories and projections agree. Upcasters chain by type and
version (`v1 → v2 → v3`); among several for the same type and version the highest `priority()` wins. Make one a
component:

<!-- source: packages/eventsourcing/tests/CapstoneFixtures/LegacyOrderPlacedUpcaster.php -->
```php
    public function upcast(StoredEvent $event): StoredEvent
    {
        $payload = $event->payload;
        $payload['total'] = $payload['amount'] ?? 0;
        unset($payload['amount']);

        return $event->upcasted('order.placed', 2, $payload);
    }
```

An event whose version, after upcasting, is still not the registered one is refused with the upcaster it is
missing. `CallbackEventUpcaster` builds one from a closure.

## Publishing to firefly/eda

<!-- source: skeleton/config/firefly.php -->
```php
        'publish' => [
            'enabled' => env('FIREFLY_EVENTSOURCING_PUBLISH_ENABLED', false),
            'destination' => env('FIREFLY_EVENTSOURCING_PUBLISH_DESTINATION', 'eventsourcing.events'),
            'exclude-aggregate-types' => ['orchestration.execution'],
        ],
```

With `publish.enabled`, every appended event is published on `publish.destination` with its metadata and its
`x-event-id`, `x-event-version`, `x-aggregate-id`, `x-aggregate-type`, `x-aggregate-version` and
`x-occurred-at` headers:

- under `firefly.eda.provider=database` or `postgres` the outbox row is written IN the append's transaction, so
  the event and its publication commit or roll back together. The event store and the outbox must share a
  connection, or the boot is refused naming both keys;
- under any other provider the events are published after the append commits, and a rolled-back append
  publishes nothing.

Either way an event is published exactly once by this package. Aggregate types in
`publish.exclude-aggregate-types` (the orchestration executions by default) are never published.

## Tenancy

With `tenancy.enabled`, every appended event is stamped with the tenant your `TenantResolver` returns, an
append or load without a current tenant is refused, and loading another tenant's stream is refused as if it
did not exist. Global reads take a tenant filter; projections read every tenant by design. Aggregate ids must
be unique across tenants (use UUIDs). Bind your own resolver:

<!-- illustrative: an application's own tenant resolver -->
```php
<?php

declare(strict_types=1);

namespace App\Tenancy;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Store\TenantResolver;

#[Component]
final class RequestTenantResolver implements TenantResolver
{
    public function currentTenant(): ?string
    {
        $tenant = request()->header('X-Tenant-Id');

        return is_string($tenant) && $tenant !== '' ? $tenant : null;
    }
}
```

## Event-sourced orchestration persistence

`firefly.orchestration.persistence.provider=event-sourced` stores every saga, TCC and workflow execution as an
event-sourced aggregate (`orchestration.execution`, its id the correlation id) in this package's event store,
snapshotted every `snapshot.threshold` events:

<!-- illustrative: an application's own .env file -->
```dotenv
FIREFLY_EVENTSOURCING_STORE=database
FIREFLY_ORCHESTRATION_PERSISTENCE=event-sourced
```

Each checkpoint becomes events: `started` with the whole state first, then `status-changed`,
`step-status-changed`, `step-result-recorded` and `search-attributes-changed` for what a step changed, and one
`checkpointed` for anything else, so a replay gives back exactly the state that was saved. `delete()` and the
retention cleanup append a `deleted` tombstone; events are never purged.

The queries the engines run (in-flight, stale, due and search-attribute lookups, counts) read a read model:
`firefly/orchestration`'s own tables on the event store's connection, written in the same transaction as the
events (the memory store keeps an in-memory read model). Orchestration's `persistence.connection` is not read.
The read model is the inline projection `orchestration.executions`; rebuild it from the events with
`php artisan firefly:eventsourcing:projections:rebuild orchestration.executions`. Its migration is loaded while
the provider is `event-sourced` over the database store; publish it with
`--tag=firefly-eventsourcing-orchestration-migrations`. The provider passes the same contract as `memory`,
`cache` and `database` on every server, and runs the orchestration capstones end to end.

## Configuration

| Key (`firefly.eventsourcing.`) | Default | What it does |
|---|---|---|
| `store.provider` | `memory` | `memory` or `database` (`FIREFLY_EVENTSOURCING_STORE`) |
| `store.connection` | `null` | The connection for every eventsourcing table (`FIREFLY_EVENTSOURCING_DB_CONNECTION`) |
| `store.batch-size` | `500` | Events numbered per round, and the default page of a global read |
| `snapshot.enabled` | `true` | Snapshots for `SnapshotAware` aggregates |
| `snapshot.store` | `null` | `memory` or `database`; null follows `store.provider` |
| `snapshot.threshold` | `50` | A snapshot each time the version crosses a multiple; `0` never |
| `snapshot.keep-count` | `3` | Snapshots kept per aggregate |
| `projection.checkpoint-store` | `null` | `memory` or `database`; null follows `store.provider` |
| `projection.batch-size` | `100` | Events per projection batch (one transaction) |
| `projection.lease-ttl` | `30s` | The per-batch lease on the `DistributedLock` |
| `projection.poll-interval` | `1s` | The idle sleep in live mode |
| `projection.max-lag` | `0` | The lag that turns the health component DOWN; `0` never |
| `publish.enabled` | `false` | Publish appended events to `firefly/eda` |
| `publish.destination` | `eventsourcing.events` | The eda destination |
| `publish.exclude-aggregate-types` | `['orchestration.execution']` | Aggregate types never published |
| `tenancy.enabled` | `false` | Stamp and check the `TenantResolver`'s tenant |
| `metadata.capture-user` | `true` | Stamp the authenticated user's id |
| `health.enabled` | `true` | The `eventsourcing` health component |

A value the package cannot honour (an unknown provider, a negative number, an unparseable duration, a lease
under one second, the event store and the outbox on two connections) refuses the boot, naming its key.

## Limits

- Events are immutable and never purged; a deleted aggregate is a tombstone in its stream.
- An aggregate id belongs to one aggregate type, and with tenancy on, to one tenant.
- SQLite has one writer per database file: correct under concurrency, but appends from many processes queue.
- The lease is per batch: the `DistributedLock` port has no extend, so `lease-ttl` must exceed one batch.
  Fencing on the checkpoint covers a lease that expired anyway.
- There is no Mongo or Kafka event store.
