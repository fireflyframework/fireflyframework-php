<span class="eyebrow">Part II — Modeling & Persisting the Domain · Chapter 6</span>

# Domain-Driven Design {.chtitle}

By the end of this chapter you will know the difference `firefly/domain` draws between an `Entity` and a `ValueObject`, how `AggregateRoot` (and its trait twin, `HasDomainEvents`) makes an object the sole source of its own domain events, how a `DomainEvent` is built and identified, how the `Wallet` aggregate enforces its own overdraft and currency rules with nothing outside it able to bypass them, and — closing the loop opened in Chapter 5 — exactly how a raised event travels from an aggregate method to a published fact on the other side of a committed transaction.

!!! note "New term: invariant"
    An **invariant** is a rule that must always hold, no matter which code path got there — for `Wallet`, "the balance is never negative" is an invariant, not a convention. An invariant lives *inside* the object it protects; a rule enforced only by a service that callers can bypass is not truly an invariant at all.

---

## Entities and value objects

`firefly/domain` is deliberately the smallest package in the whole framework: `Entity`, `ValueObject`, `AggregateRoot`, and `DomainEvent` — four plain-PHP building blocks with **zero framework dependency and zero reflection**. (A dedicated test greps the package's own source for `ReflectionClass`/`ReflectionMethod`/`getAttributes` and asserts no hits — your domain model never has to know LaraFly exists.)

`Entity` draws the first of DDD's two foundational distinctions: identity, not value, decides whether two entities are the same thing.

```php
abstract class Entity
{
    public function __construct(protected int|string|null $id = null) {}

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function isTransient(): bool
    {
        return $this->id === null;
    }

    public function equals(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if ($this::class !== $other::class) {
            return false;
        }

        if ($this->isTransient() || $other->isTransient()) {
            return false;
        }

        return $this->id === $other->id;
    }
}
```

Two entities are `equals()` only when they are the same concrete class **and** have equal, non-null ids. A **transient** entity — one whose id is still `null`, not yet persisted — is equal only to itself, by plain object identity; two distinct transient instances are never equal even with identical other state. PHP has no runtime generics, so `id()` is typed `int|string|null` — wide enough to cover both an auto-increment integer key and a string id like `Wallet`'s own `wlt-…`.

`ValueObject` is the second distinction: no identity at all, immutable by convention, equal purely by the values it holds.

```php
interface ValueObject {}
```

It is a bare marker interface on purpose — a value object's *equality* is supplied separately, by a small trait:

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

`get_object_vars($this)`, not `ReflectionProperty` — deliberately, so `firefly/domain` stays reflection-free — compares every scope-visible property. This is exactly right for a **flat** `readonly` value object; a value object that nests another value object should compose equality by delegating to the nested object's own `equals()` rather than trusting `==` to recurse correctly through the outer property array.

!!! note "New term: aggregate"
    An **aggregate** is a small cluster of objects that must stay consistent as a group, entered and mutated through exactly one object: its **aggregate root**. The next section builds one.

### `Money`: the textbook value object

`Money` is `firefly/domain`'s `ValueObject` applied to Lumen's actual currency handling — real, shipped code from `samples/lumen/src/Domain/Money.php`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\ValueObject;
use Firefly\Domain\ValueObjectEquality;
use Firefly\Kernel\Exception\Business\ConflictException;

final readonly class Money implements ValueObject
{
    use ValueObjectEquality;

    public function __construct(public int $minorUnits, public Currency $currency) {}

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits + $other->minorUnits, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->minorUnits - $other->minorUnits, $this->currency);
    }

    public function isPositive(): bool
    {
        return $this->minorUnits > 0;
    }

    public function isNegative(): bool
    {
        return $this->minorUnits < 0;
    }

    public function __toString(): string
    {
        return number_format($this->minorUnits / 100, 2, '.', '').' '.$this->currency->value;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new ConflictException(
                "cannot combine {$this->currency->value} with {$other->currency->value}"
            );
        }
    }
}
```

Three design choices here repay close reading. `$minorUnits` is stored as an **integer** — cents, not a `float` — precisely so financial arithmetic never accumulates rounding error; `Money(1050, Currency::EUR)` is exactly €10.50, and `add`/`subtract` operate on that integer directly. `add()` and `subtract()` both return a **new** `Money` instance — `final readonly class` makes this the only option, not a discipline you have to remember — so a deposit never mutates the amount it started from; nothing else in the codebase can be silently holding a stale reference to a `Money` that just changed underneath it. And `assertSameCurrency()` is a private helper both public methods route through first, so combining EUR and USD amounts is a `ConflictException` raised at the exact call site of the mistake, never a silently-wrong sum discovered during reconciliation.

`final readonly class Money implements ValueObject { use ValueObjectEquality; }` is the entire equality story — two `Money(1050, Currency::EUR)` instances constructed independently are `equals()` to each other, because `ValueObjectEquality` compares their public state, not their object identity.

!!! laravel "Laravel parity"
    `ValueObject` + `ValueObjectEquality` map to the `@ValueObject`/`@Embeddable` idea from the JPA world, and to PHP's own `readonly` class feature doing the immutability half of the job. In a plain Laravel/Eloquent application, the nearest equivalent to `Money` is an Eloquent cast object — except `Money` here has no idea Eloquent exists, and is exercised in a unit test with two constructor arguments and nothing else.

---

## The aggregate root: the only source of its own events

`Money` solves representation. Something still has to *own* the decision of whether a deposit or withdrawal is allowed at all — that is the aggregate root's job. `AggregateRoot` extends `Entity` and adds exactly one thing: a private buffer of pending domain events, and the single protected method that appends to it.

```php
abstract class AggregateRoot extends Entity implements RecordsDomainEvents
{
    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    protected function raiseEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function clearEvents(): void
    {
        $this->pendingEvents = [];
    }
}
```

`raiseEvent()` is `protected` — deliberately. Only the aggregate's *own* methods can call it, which is what makes an aggregate the **consistency boundary** the chapter introduction promised: no external caller can reach in and make the aggregate emit an event on its behalf, the same way no external caller can reach in and mutate its state without going through a method that enforces the rules. `pendingEvents()` is a non-draining snapshot — safe to call from a test assertion or a log line without side effects. `pullEvents()` drains the buffer and hands it to whoever asked, which is what the framework's after-commit machinery calls. `clearEvents()` drops the buffer without publishing anything — used on rollback.

`RecordsDomainEvents` is the interface that names exactly this drain-side contract, deliberately **without** `raiseEvent()` — because only the aggregate itself may raise its own events, so that method stays `protected` on whichever class implements the interface:

```php
interface RecordsDomainEvents
{
    /** @return list<DomainEvent> */
    public function pendingEvents(): array;

    /** @return list<DomainEvent> */
    public function pullEvents(): array;

    public function clearEvents(): void;
}
```

### Why `Wallet` doesn't extend `AggregateRoot`

Here is where LaraFly's aggregate story has to solve a problem PyFly and Java never face: PHP has single inheritance, and `Wallet` needs to be a **persisted Eloquent model** as well as an aggregate root. It cannot `extends AggregateRoot` and `extends Model` at the same time. `HasDomainEvents` is the trait that closes this gap — the exact same buffer and the exact same four methods as `AggregateRoot`, but as a trait any class can `use`, regardless of what it already extends:

```php
trait HasDomainEvents
{
    /** @var list<DomainEvent> */
    private array $pendingEvents = [];

    protected function raiseEvent(DomainEvent $event): void
    {
        $this->pendingEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function pendingEvents(): array
    {
        return $this->pendingEvents;
    }

    /** @return list<DomainEvent> */
    public function pullEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    public function clearEvents(): void
    {
        $this->pendingEvents = [];
    }
}
```

Here is the whole, real `Wallet` aggregate — `samples/lumen/src/Domain/Wallet.php` — combining exactly this trait with `extends Model` and `implements RecordsDomainEvents`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain;

use Firefly\Domain\HasDomainEvents;
use Firefly\Domain\RecordsDomainEvents;
use Firefly\Kernel\Exception\Business\ConflictException;
use Illuminate\Database\Eloquent\Model;
use Lumen\Domain\Event\FundsDeposited;
use Lumen\Domain\Event\FundsWithdrawn;
use Lumen\Domain\Event\WalletOpened;

final class Wallet extends Model implements RecordsDomainEvents
{
    use HasDomainEvents;

    protected $table = 'wallets';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'owner_id', 'currency', 'balance_minor'];

    public $timestamps = false;

    public static function open(string $id, string $ownerId, Currency $currency): self
    {
        if (trim($ownerId) === '') {
            throw new ConflictException('owner_id is required');
        }

        $wallet = new self([
            'id' => $id,
            'owner_id' => $ownerId,
            'currency' => $currency->value,
            'balance_minor' => 0,
        ]);
        $wallet->raiseEvent(new WalletOpened($id, $ownerId, $currency->value));

        return $wallet;
    }

    public function currency(): Currency
    {
        $value = $this->getAttribute('currency');

        return Currency::from(match (true) {
            is_string($value) => $value,
            default => '',
        });
    }

    public function balanceMoney(): Money
    {
        $value = $this->getAttribute('balance_minor');

        $minor = match (true) {
            is_int($value) => $value,
            is_numeric($value) => (int) $value,
            default => 0,
        };

        return new Money($minor, $this->currency());
    }

    /**
     * `getKey()` is declared `mixed` (a primary key may be any attribute type); Wallet's own key is always the
     * string `id` set in open(), so this narrows for the event payload rather than casting mixed directly.
     */
    private function walletId(): string
    {
        $key = $this->getKey();

        return match (true) {
            is_string($key) => $key,
            is_int($key) => (string) $key,
            default => '',
        };
    }

    public function deposit(Money $amount): void
    {
        $this->assertCurrency($amount);
        if (! $amount->isPositive()) {
            throw new ConflictException('deposit amount must be > 0');
        }
        $new = $this->balanceMoney()->add($amount);
        $this->setAttribute('balance_minor', $new->minorUnits);
        $this->raiseEvent(new FundsDeposited(
            $this->walletId(), $amount->minorUnits, $amount->currency->value, $new->minorUnits
        ));
    }

    public function withdraw(Money $amount): void
    {
        $this->assertCurrency($amount);
        if (! $amount->isPositive()) {
            throw new ConflictException('withdrawal amount must be > 0');
        }
        $remaining = $this->balanceMoney()->subtract($amount);
        if ($remaining->isNegative()) {
            throw new ConflictException(
                "cannot withdraw {$amount}; balance is {$this->balanceMoney()}"
            );
        }
        $this->setAttribute('balance_minor', $remaining->minorUnits);
        $this->raiseEvent(new FundsWithdrawn(
            $this->walletId(), $amount->minorUnits, $amount->currency->value, $remaining->minorUnits
        ));
    }

    private function assertCurrency(Money $amount): void
    {
        if ($amount->currency !== $this->currency()) {
            throw new ConflictException('currency mismatch');
        }
    }
}
```

This is a genuinely different shape from a language with proper multiple inheritance — and it is deliberate, not a workaround. `Wallet` **is** the Eloquent row (`protected $table = 'wallets'`, `$fillable`, a non-incrementing string key) **and** the aggregate root, in one object. There is no separate `WalletEntity` ORM class and no mapper crossing a boundary between them for this aggregate: `Wallet::open()` both builds the row's attributes *and* raises `WalletOpened` in the same static factory method, and `deposit()`/`withdraw()` both mutate the Eloquent attribute *and* raise their event in the same call. `HasDomainEvents`'s buffer, `$pendingEvents`, is a genuinely **declared** private property, not a database attribute — Eloquent's magic `__get`/`__set` never intercepts it, because a declared property always shadows the magic accessors.

Three invariants live inside these three methods, and nowhere else. `open()` refuses a blank `owner_id`. `deposit()` and `withdraw()` both refuse a non-positive amount and a currency mismatch (via the shared `assertCurrency()` helper). `withdraw()` additionally refuses to let the balance go negative — the overdraft rule. Every one of these checks runs *before* `setAttribute()` is called, so a rejected operation leaves the wallet's persisted attributes completely untouched, and the corresponding event is never raised for an operation that never happened.

!!! warning "Keep invariants in the model, not the service"
    If the overdraft check lived in a service method instead of inside `withdraw()`, anything that called `$wallet->save()` directly — a background job, an admin script, a future developer in a hurry — could silently bypass it. Because the check is inside the aggregate's own method, there is no code path into an overdrawn `Wallet` at all: the rule and the only door into the state it protects are the same piece of code.

---

## Domain events: `DomainEvent`, and the four Lumen ships

`DomainEvent` is the flat, immutable base every concrete event extends. It auto-populates two fields you never have to set yourself:

```php
abstract readonly class DomainEvent
{
    public string $eventId;

    public DateTimeImmutable $occurredAt;

    public function __construct(?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        $this->eventId = $eventId ?? self::uuid4();
        $this->occurredAt = $occurredAt ?? new DateTimeImmutable;
    }

    public function eventId(): string
    {
        return $this->eventId;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function eventType(): string
    {
        $class = static::class;
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }
}
```

`eventId` is a fresh uuid-v4 built from `random_bytes()` — no `ramsey/uuid` dependency, no reflection — and `occurredAt` defaults to "now" at construction time; both accept an explicit value too, for reconstitution or a test that needs a fixed timestamp. `eventType()` is the concrete class's own short name — `strrpos`/`substr` on `static::class`, again no reflection — and it is this string, not the PHP class, that a downstream listener matches against (you will see exactly that matching rule in this chapter's closing section).

Lumen ships four such events, one per state transition the wallet or a transfer can produce. Here are the three `Wallet` itself raises — real, shipped code from `samples/lumen/src/Domain/Event/`:

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class WalletOpened extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public string $ownerId,
        public string $currency,
    ) {
        parent::__construct();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class FundsDeposited extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
        public string $currency,
        public int $balanceMinor,
    ) {
        parent::__construct();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Lumen\Domain\Event;

use Firefly\Cqrs\Attributes\PublishDomainEvent;
use Firefly\Domain\DomainEvent;

#[PublishDomainEvent('wallet.events')]
final readonly class FundsWithdrawn extends DomainEvent
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
        public string $currency,
        public int $balanceMinor,
    ) {
        parent::__construct();
    }
}
```

A fourth event, `TransferCompleted`, is raised not by `Wallet` itself but by the application layer once both legs of a transfer succeed (the next section shows exactly where). Every one carries `#[PublishDomainEvent('wallet.events')]` — an attribute from `firefly/cqrs`, not `firefly/domain`, which names the **destination** an event republishes to once it crosses into the integration-event world; `firefly/domain` itself has no opinion about destinations at all, only about raising and draining.

Notice what each event does and does not carry. `FundsDeposited` and `FundsWithdrawn` both carry the **post-operation** balance (`$balanceMinor`), not just the amount that moved — a subscriber updating a read-model never has to reload the wallet to learn its new balance; everything it needs is already in the fact it received. That design pays off directly in `LedgerProjector`, later in this chapter.

!!! laravel "Laravel parity"
    `AggregateRoot`/`HasDomainEvents` + `DomainEvent` correspond to Spring Data's `AbstractAggregateRoot` with its `registerEvent()`/`@DomainEvents`/`@AfterDomainEventPublication` mechanism. `raiseEvent()` is `registerEvent()`; `pullEvents()` is the drain `@AfterDomainEventPublication` performs automatically. In plain Laravel/Eloquent, the closest built-in idiom is a model firing a Laravel event directly from inside a mutator — except that fires **immediately**, win or lose, where `raiseEvent()` only buffers, and nothing is published until a surrounding transaction actually commits.

---

## The after-commit event model

Buffering an event is not the same as publishing it — and the gap between the two is exactly what protects you from a listener ever reacting to a change that got rolled back. The full path from a raised event to a published fact runs through three packages working together:

1. A mutating aggregate method — `Wallet::deposit()`, say — calls `raiseEvent()`, appending to the private buffer. Nothing is published yet.
2. When the entity is persisted through a Firefly repository, `EloquentRepository::save()` (Chapter 5's closing section) registers it with `firefly/data`'s `AggregateTracker` — but **only** while a transaction is actively open on that entity's own connection.
3. When the surrounding `#[Transactional]` method commits, the framework drains every tracked recorder's `pullEvents()` and publishes each one, scheduled via Laravel's own `DB::afterCommit()` on the same connection the transaction ran on.
4. Laravel only fires `afterCommit()` callbacks after the **outermost** real commit, and **discards** them entirely on rollback — so a listener only ever observes an event from a unit of work that genuinely succeeded.

`DepositHandler` — the real, shipped command handler behind `WalletController::deposit()` from Chapter 4 — is this whole cycle in five lines:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Deposit: loads the aggregate, credits the amount in the wallet's own currency, persists, and returns the
 * new balance in minor units. #[Transactional] for the same load-bearing reason as OpenWalletHandler — it is the only
 * thing that runs save() at transactionLevel() > 0 so the aggregate is tracked and FundsDeposited publishes on commit.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler.
 */
#[CommandHandler]
class DepositHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(Deposit $command): int
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $wallet->deposit(new Money($command->amountMinor, $wallet->currency()));
        $this->wallets->save($wallet);

        return $wallet->balanceMoney()->minorUnits;
    }
}
```

`#[Transactional]` (the subject of a later chapter in full) is what makes step 2 above possible at all: without it, `save()` would flush the write but never see `transactionLevel() > 0`, so the aggregate would never be tracked and `FundsDeposited` would never publish, no matter how correctly `Wallet::deposit()` raised it. `$wallet->deposit(...)` runs the invariant checks and queues the event; `$this->wallets->save($wallet)` persists the row **and** registers the aggregate for after-commit dispatch, in the same call; the method returns, the transaction commits, and only then does `FundsDeposited` reach any listener.

`TransferHandler` shows the same cycle with two aggregates and a genuine all-or-nothing guarantee:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Transfer: an ATOMIC debit(source) + credit(destination) + save(both) inside one #[Transactional] boundary.
 *
 * The transferred value carries the SOURCE currency (a transfer moves a specific amount of money, not an abstract
 * number of minor units), so the credit leg deposits that same Money into the destination. When the two wallets share
 * a currency the credit succeeds and both saves commit together; when they differ the destination's deposit() throws
 * a currency-mismatch ConflictException AFTER the source was already debited, and — because #[Transactional] rolls
 * back on any Throwable — the source debit is undone too. That is the money-cannot-vanish invariant: there is no path
 * where the source loses funds the destination never receives.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler (same reason as the S4 handlers).
 */
#[CommandHandler]
class TransferHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional(propagation: Propagation::REQUIRED)]
    public function handle(Transfer $command): void
    {
        $source = $this->wallets->findById($command->sourceWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->sourceWalletId}] not found");
        $destination = $this->wallets->findById($command->destinationWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->destinationWalletId}] not found");

        $amount = new Money($command->amountMinor, $source->currency());
        $source->withdraw($amount);       // debit (raises FundsWithdrawn)
        $this->wallets->save($source);    // persist + track the debit INSIDE the tx, so it can genuinely roll back
        $destination->deposit($amount);   // credit — throws on currency mismatch -> whole tx rolls back
        $this->wallets->save($destination);
        // commit here -> FundsWithdrawn + FundsDeposited drain atomically after the unit of work commits.
    }
}
```

If the destination's `deposit()` throws — a currency mismatch — `#[Transactional]`'s default `rollbackFor` (every `Throwable`) rolls the whole method back, undoing the source's debit too. Neither `FundsWithdrawn` nor `FundsDeposited` was published yet at that point — both were only buffered on their respective aggregates — so a listener never sees the half of a transfer that never actually happened. Money can neither vanish nor double: either both legs commit and both events publish, or neither does.

!!! note "Rehydration and the factory"
    Every `findById()` call above rebuilds a `Wallet` from a stored row through Eloquent's own hydration — never through `Wallet::open()`. That is correct: `open()` is for **new** wallets, and calling it again on an already-persisted row would re-raise `WalletOpened` for a wallet that has existed for months. A `Wallet` loaded from storage is exactly as valid as a freshly opened one; it simply carries no fresh event, because nothing new happened to it yet.

---

## From domain event to read model: `LedgerProjector`

The last leg of the journey — a published `FundsDeposited` actually reaching something useful — closes with a real, shipped listener. `LedgerProjector` turns every committed wallet event into an append-only row in `ledger_entries`, the table `GetLedgerHandler` (Chapter 4's `WalletController::ledger()`) reads back:

```php
<?php

declare(strict_types=1);

namespace Lumen\Application\Listener;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Lumen\Domain\LedgerEntry;

/**
 * The read-model projector: turns committed wallet integration events into `ledger_entries` rows (an append-only
 * audit ledger the GetLedger query reads back). It closes the full event chain the sample exercises — a
 * #[Transactional] command commits, the DomainEventDispatcher drains the aggregate's domain events after commit, the
 * firefly/cqrs domain->integration bridge republishes each one to the eda EventPublisher (the memory InMemoryEventBus
 * on the default provider), and the SubscriberRegistry delivers the matching envelope here.
 *
 * The #[EventListener] MUST enumerate the event TYPE names, not the #[PublishDomainEvent('wallet.events')] DESTINATION:
 * SubscriberRegistry::deliver() calls fnmatch($pattern, $envelope->eventType), matching the pattern against the
 * eventType (the short class name, e.g. 'FundsDeposited') and NEVER against the destination. A 'wallet.*'-style pattern
 * would therefore never match any wallet event and this projector would silently never fire.
 */
#[Component]
final class LedgerProjector
{
    #[EventListener(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn', 'TransferCompleted'])]
    public function onWalletEvent(EventEnvelope $envelope): void
    {
        // The envelope payload is array<string, mixed> (get_object_vars of the domain event, seen through the broker
        // boundary), so each field is narrowed to its projected type — a WalletOpened carries no amount/balance, so
        // those default to 0, and TransferCompleted carries no walletId, so it defaults to ''.
        $walletId = $envelope->payload['walletId'] ?? '';
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

Read the docblock closely — it names every hop the event actually takes: the `#[Transactional]` command commits; `firefly/data`'s dispatcher drains the aggregate's buffered events; `firefly/cqrs`'s domain-to-integration bridge republishes each one onto the `firefly/eda` event bus (the in-memory bus by default); and `SubscriberRegistry` delivers the resulting `EventEnvelope` to every `#[EventListener]` whose pattern matches the event's **type name** — `FundsDeposited`, not the `#[PublishDomainEvent('wallet.events')]` destination string. `$envelope->payload['balanceMinor']` existing at all, with no need to reload the wallet, is the direct payoff of `FundsDeposited` carrying the post-operation balance from the moment `Wallet::deposit()` raised it.

::: figure art/figures/cqrs-eda-bridge.svg | Figure 6.1 — The domain-to-integration bridge: a command commits, buffered domain events drain and republish onto the event bus, and a listener like LedgerProjector reacts on the other side.

A full account of `firefly/cqrs`'s command bus and `firefly/eda`'s event bus is a later chapter's territory. What matters here is the shape: `Wallet` never imports `firefly/eda`, never imports `LedgerProjector`, and has no idea a ledger exists. It only raises a fact. Everything downstream of that fact is somebody else's concern — which is the entire point of a domain event.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `Entity` | Identity-based equality: same class, equal non-null ids; a transient entity equals only itself |
| `ValueObject` / `ValueObjectEquality` | A marker for no-identity, immutable state, plus structural equality via `get_object_vars()` |
| `AggregateRoot` | The persistence-free consistency boundary: a private event buffer plus a `protected raiseEvent()` |
| `HasDomainEvents` | The identical buffer as a trait, for a class (like an Eloquent `Model`) that already extends something else |
| `RecordsDomainEvents` | The drain-side contract (`pendingEvents`/`pullEvents`/`clearEvents`) — deliberately without `raiseEvent()` |
| `DomainEvent` | A flat, immutable base auto-populating `eventId` (uuid-v4) and `occurredAt`; `eventType()` is the class's short name |
| `Wallet::open`/`deposit`/`withdraw` | The three real invariants — required owner, positive amount, no overdraft, currency match — enforced before any mutation |
| The after-commit model | `save()` tracks a `RecordsDomainEvents` entity while a transaction is open; events drain and publish only after that transaction commits |
| `LedgerProjector` | A real `#[EventListener]` reacting to the published facts, matched by event type name |

---

## Try it yourself {.exercises}

1. **Add a `freeze()` behaviour.** In a scratch copy of the project (not the shipped `samples/lumen` package), add a `WalletFrozen` event (`walletId: string`, `reason: string`), a `freeze(string $reason): void` method on `Wallet` that raises it, and guard `deposit()`/`withdraw()` with a check that throws `ConflictException` when the wallet is frozen. Write a small test proving a frozen wallet's balance never changes on a deposit attempt, and that no `FundsDeposited` is ever queued for the rejected attempt.
2. **Prove the invariant holds.** Open a wallet, deposit a small amount, then attempt to withdraw more than the balance. Assert the thrown `ConflictException`, then assert the wallet's `balanceMoney()` is completely unchanged and `pendingEvents()` shows no `FundsWithdrawn` — the rule fired before any mutation, exactly as this chapter describes.
3. **Trace the transaction boundary.** Temporarily remove `#[Transactional]` from a copy of `DepositHandler`, deposit into a wallet, and confirm (via a fresh read in a new request) that the row was never actually committed — proving that `EloquentRepository::save()`'s *flush* alone is not durability, and that domain-event publication depends on the same commit.
