# Domain (DDD)

`firefly/domain` is LaraFly's DDD base: `Entity`, `ValueObject`, `AggregateRoot`, and `DomainEvent` — plain PHP
building blocks with **zero framework dependency and zero reflection** (a dedicated test greps the package's
`src/` for `ReflectionClass`/`ReflectionMethod`/`getAttributes` and asserts no hits). The after-commit wiring
that turns a raised event into a published one lives one layer up, in [`firefly/data`](data.md) —
keeping this package pure.

## `Entity` — identity, not value, defines equality

```php
abstract class Entity
{
    public function __construct(protected int|string|null $id = null) {}

    public function id(): int|string|null;
    public function isTransient(): bool;   // true when $id === null
    public function equals(self $other): bool;
}
```

Two entities are `equals()` when they are the **same concrete class** (`$this::class === $other::class`) **and**
have equal, non-null ids. A **transient** entity — one whose id is still `null`, not yet persisted — is equal
only to itself (plain object identity, `$this === $other`); two distinct transient instances are never equal
even with identical other state. PHP has no runtime generics, so `id()` is typed `int|string|null` (covers
auto-increment and uuid ids alike); a subclass may narrow its return type covariantly.

## `ValueObject` and `ValueObjectEquality`

`ValueObject` is a marker interface — no identity, immutable by convention (a `readonly` class), equal by
value:

```php
interface ValueObject {}
```

The `ValueObjectEquality` trait supplies structural equality for a flat `readonly` VO:

```php
trait ValueObjectEquality
{
    public function equals(self $other): bool
    {
        return get_class($this) === get_class($other)
            && get_object_vars($this) == get_object_vars($other);
    }
}
```

It compares via `get_object_vars($this)` — **deliberately not `ReflectionProperty`**, so the package stays
reflection-free — which means it only sees scope-visible properties and is suitable for a flat value object;
a VO nesting another VO should compose equality (delegate to the nested VO's own `equals()`) rather than rely
on `==` recursing correctly through the outer array compare. A VO not implementing the trait can still
implement `equals()` by hand.

## `AggregateRoot` — the consistency boundary and the only event source

`AggregateRoot extends Entity` and is the **only** thing in a domain model that raises events:

```php
abstract class AggregateRoot extends Entity implements RecordsDomainEvents
{
    protected function raiseEvent(DomainEvent $event): void;  // protected: only the aggregate raises its own events

    public function pendingEvents(): array;  // a non-draining snapshot; repeated reads never drain
    public function pullEvents(): array;     // drains the buffer AND returns it
    public function clearEvents(): void;     // drops the buffer (e.g. on rollback)
}
```

A mutating method appends to a private buffer via the protected `raiseEvent()`; nothing outside the aggregate
can raise on its behalf. `pendingEvents()` is read-only (safe to call from a test assertion or a log line
without side effects); `pullEvents()` is the drain-and-hand-off used by the dispatcher; `clearEvents()` empties
the buffer without publishing (used when a unit of work rolls back).

`AggregateRoot` satisfies the `RecordsDomainEvents` interface — the drain-side contract (`pendingEvents`/
`pullEvents`/`clearEvents`, deliberately **without** `raiseEvent`, since only the aggregate itself may raise)
that the unit-of-work tracker and after-commit dispatcher depend on. A persistence-free aggregate extends
`AggregateRoot` directly. An Eloquent `Model` can't — single inheritance is already spent — so it instead
`use`s the `HasDomainEvents` trait and `implements RecordsDomainEvents` itself, giving one object that is
**both** a persisted `Model` **and** an auto-dispatching aggregate:

```php
final class Order extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    public function place(): void
    {
        $this->raiseEvent(new OrderPlaced($this->status));
    }
}
```

`HasDomainEvents`'s buffer is a **real declared property**, not a database attribute — Eloquent's magic
`__get`/`__set` never intercepts it, because a declared property shadows the magic accessors.

## `DomainEvent` — flat, immutable, reflection-free

```php
abstract readonly class DomainEvent
{
    public string $eventId;              // uuid-v4, generated from random_bytes — no ramsey/uuid dependency
    public DateTimeImmutable $occurredAt;

    public function __construct(?string $eventId = null, ?DateTimeImmutable $occurredAt = null);

    public function eventId(): string;
    public function occurredAt(): DateTimeImmutable;
    public function eventType(): string;  // the concrete class's short name, e.g. "OrderPlaced"
}
```

Both `eventId` and `occurredAt` accept an explicit value in the constructor (for reconstitution/testing) and
otherwise default to a freshly generated uuid-v4 and "now". `eventType()` strips the namespace off
`static::class` with a plain `strrpos`/`substr` — no reflection. A concrete event is `final readonly` and
calls `parent::__construct()`:

```php
final readonly class OrderPlaced extends DomainEvent
{
    public function __construct(public string $status)
    {
        parent::__construct();
    }
}
```

## The after-commit event model

Raising an event only buffers it — nothing is published until the surrounding unit of work actually commits.
The flow:

1. A mutating aggregate method calls `raiseEvent()`, appending to the private buffer.
2. A repository `save()` call (`EloquentRepository::save()`) registers the saved recorder with `firefly/data`'s
   `AggregateTracker` **while a transaction is active** on that recorder's own connection.
3. When the surrounding `#[Transactional]` method (or `TransactionTemplate::execute()` call) **commits**, the
   framework drains every tracked recorder's `pullEvents()` and publishes each one through
   `ApplicationEventPublisher`, scheduled via `DB::afterCommit()` on the same connection the transaction ran on.
4. Laravel only fires `afterCommit()` callbacks after the **outermost** real commit, and **discards** them on
   rollback — so a listener only ever observes an event from a unit of work that actually succeeded, never
   from one that rolled back.

```php
#[Service]
#[Transactional]
class PlaceOrderService
{
    public function __construct(private readonly OrderRepository $orders) {}

    public function placeOrder(string $status): Order
    {
        $order = new Order(['status' => $status]);
        $order->place();          // raises OrderPlaced into the pending buffer
        $this->orders->save($order); // persists the row AND tracks the aggregate

        return $order;
        // commit here -> OrderPlaced is drained and published
    }

    public function placeOrderAndFail(string $status): void
    {
        $order = new Order(['status' => $status]);
        $order->place();
        $this->orders->save($order);

        throw new RuntimeException('rollback');
        // rollback here -> no row, no event: the listener never runs
    }
}
```

Because `firefly/domain` stays pure PHP with no framework dependency, this after-commit wiring — the
`AggregateTracker`, the `DomainEventDispatcher`, and the `DB::afterCommit` hook — lives in
[`firefly/data`](data.md), not here. See [Transactions](transactional.md) for the full `#[Transactional]`
model this wiring rides on, and the [`DomainEventDispatcher::publishAfterCommit()`](transactional.md) escape
hatch for a recorder that isn't saved through a Firefly repository.
