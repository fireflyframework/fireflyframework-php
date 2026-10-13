<span class="eyebrow">Part III — Coordinating and Securing the Application · Chapter 9B</span>

# Event Sourcing: Storing What Happened {.chtitle}

By the end of this chapter you will know how to store what happened instead of what is. You will model Lumen's loans as an event-sourced aggregate (`EventSourcedAggregateRoot`, `recordThat()`) whose events carry a stable name and a version (`#[EventType]`), append them to the database event store under optimistic concurrency (`firefly.eventsourcing.store.provider`, `ConcurrencyException`), and rebuild a loan from its history or from a snapshot (`SnapshotAware`, `firefly.eventsourcing.snapshot.threshold`) through an `EventSourcedRepository`. You will keep a read model current with a `Projection` and its checkpoint (`firefly:eventsourcing:projections:run`, `firefly:eventsourcing:projections:rebuild`), read events stored under an old field name through an `EventUpcaster`, publish every appended event through the outbox in the append's own transaction (`firefly.eventsourcing.publish.enabled`), and store Chapter 9A's saga executions as event streams (`firefly.orchestration.persistence.provider`).

!!! note "New term: event sourcing"
    **Event sourcing** stores an object as the sequence of facts that happened to it — granted, repaid, repaid, settled — instead of as a row holding its latest values. The current state is what you get by applying those facts in order; nothing about the past is ever overwritten, so the history is not an audit table you hope stays in step, it is the record itself.

---

## What a loan remembers

A `Wallet` is a row: Chapter 5 saves its balance, and Chapter 6's `LedgerProjector` writes a ledger line after each change commits. That is the right model for a balance. A loan is different: what matters is the history — how much was lent, every repayment and when, the moment it was settled — and the balance is just arithmetic over it. So Lumen stores loans the other way round. No table holds a loan's balance; the event store holds its events, and the loan is rebuilt from them whenever a command needs it. The wallet stays exactly as Chapters 5 to 9A built it, and the two meet in one transaction later in this chapter.

---

## Events with a name and a version

An event-sourced event is an ordinary `firefly/domain` `DomainEvent` with one attribute more. This is the repayment:

<!-- source: samples/lumen/src/Domain/Loan/Event/LoanRepaid.php -->
```php
#[EventType(name: 'loan.repaid', version: 2)]
final readonly class LoanRepaid extends DomainEvent
{
    public function __construct(
        public string $loanId,
        public int $amountMinor,
        public int $outstandingMinor,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
```

`#[EventType]` gives the event the name the store keeps — `loan.repaid`, never the class name, so the class can move or be renamed without orphaning a single stored row — and the version of its payload. The convention mapper stores the public properties and rebuilds the event through its constructor, which is why `eventId` and `occurredAt` are forwarded to the parent: a reloaded event keeps the identity and the time it was recorded with. `php artisan firefly:cache` compiles every `#[EventType]` class into the `eventsourcing.php` manifest (Chapter 13), and an event class that is not in it is refused at append, so nothing is ever stored that cannot be read back. The version is 2 because the first loans called the amount `amount`; the section on upcasting below is about that.

---

## An aggregate that is its history

`Loan` extends `EventSourcedAggregateRoot`. A command method checks its invariant against the current state and calls `recordThat()`, which applies the event at once and buffers it until the repository saves it; the protected `apply…()` methods are the only code that changes state:

<!-- source: samples/lumen/src/Domain/Loan/Loan.php -->
```php
final class Loan extends EventSourcedAggregateRoot implements SnapshotAware
{
    // …
    public static function grant(string $id, string $walletId, Money $principal): self
    {
        if (! $principal->isPositive()) {
            throw new ConflictException('a loan principal must be > 0');
        }

        $loan = new self($id);
        $loan->recordThat(new LoanGranted($id, $walletId, $principal->minorUnits, $principal->currency->value));

        return $loan;
    }

    public function repay(Money $amount): void
    {
        if ($this->settled) {
            throw new ConflictException("loan {$this->id()} is already settled");
        }
        if ($amount->currency !== $this->currency || ! $amount->isPositive()) {
            throw new ConflictException("a repayment must be a positive amount in {$this->currency->value}");
        }
        if ($amount->minorUnits > $this->outstanding()->minorUnits) {
            throw new ConflictException("cannot repay {$amount}; {$this->outstanding()} is outstanding");
        }

        $this->recordThat(new LoanRepaid($this->id(), $amount->minorUnits, $this->outstanding()->minorUnits - $amount->minorUnits));
        if ($this->outstanding()->minorUnits === 0) {
            $this->recordThat(new LoanSettled($this->id(), $this->repayments));
        }
    }
    // …
    protected function applyLoanGranted(LoanGranted $event): void
    {
        $this->walletId = $event->walletId;
        $this->currency = Currency::from($event->currency);
        $this->principalMinor = $event->principalMinor;
    }

    protected function applyLoanRepaid(LoanRepaid $event): void
    {
        $this->repaidMinor += $event->amountMinor;
        $this->repayments++;
    }

    protected function applyLoanSettled(LoanSettled $event): void
    {
        $this->settled = true;
    }
```

Two rules make this work. First, the invariants are checked *before* `recordThat()`, against state that only `apply…()` methods ever wrote, so replaying the same events always rebuilds an object that would have made the same decisions. Second, applying is routed by convention — `applyLoanRepaid()` for `LoanRepaid` — and an `apply…()` method must not fail: the event already happened, and history cannot be refused on replay. The constructor belongs to the base class and takes only the id, which is how the repository can create an empty loan before it replays one; `aggregateType()` returns `lumen.loan`, the stable name stored with every event.

---

## The event store and the repository

The events live in an `EventStore`. `memory` is the default and right for trying the model out; `database` keeps them in four portable tables on any Laravel connection:

<!-- source: skeleton/config/firefly.php -->
```php
'store' => [
    'provider' => env('FIREFLY_EVENTSOURCING_STORE', 'memory'),
    'connection' => env('FIREFLY_EVENTSOURCING_DB_CONNECTION'),
    'batch-size' => 500,
],
```

Lumen's event-sourcing harness sets `firefly.eventsourcing.store.provider=database`, and the snapshot and checkpoint stores follow it. The repository is a one-line constructor over the `EventSourcingSupport` bean:

<!-- source: samples/lumen/src/Infrastructure/Loan/LoanRepository.php -->
```php
#[Component]
#[Lazy]
final class LoanRepository extends EventSourcedRepository
{
    public function __construct(EventSourcingSupport $support)
    {
        parent::__construct($support, Loan::class);
    }
}
```

`get()` replays a loan's stream (404 `AggregateNotFoundException` when it has none), `find()` returns null instead, and `save()` appends the buffered events under the version the loan was loaded at. `#[Lazy]` is there for the sample's sake: `EventSourcingSupport` exists only where `firefly/eventsourcing`'s providers boot, and Lumen's other test harnesses never ask for a loan, so they must never build this bean at boot — the same reason `PayoutController` carries it in Chapter 9A.

---

## Snapshots: replaying less

Replaying three events is free; replaying three thousand on every command is not. An aggregate that implements `SnapshotAware` is snapshotted each time a save moves its version across a multiple of `firefly.eventsourcing.snapshot.threshold`, and a load starts from the newest snapshot and replays only what came after it:

<!-- source: skeleton/config/firefly.php -->
```php
'snapshot' => [
    'enabled' => env('FIREFLY_EVENTSOURCING_SNAPSHOT_ENABLED', true),
    'store' => env('FIREFLY_EVENTSOURCING_SNAPSHOT_STORE'),
    'threshold' => (int) env('FIREFLY_EVENTSOURCING_SNAPSHOT_THRESHOLD', 50),
    'keep-count' => 3,
],
```

`Loan` says what a snapshot holds — everything replaying would rebuild, as plain values:

<!-- source: samples/lumen/src/Domain/Loan/Loan.php -->
```php
    /** @return array{walletId: string, currency: string, principalMinor: int, repaidMinor: int, repayments: int, settled: bool} */
    public function snapshotState(): array
    {
        return [
            'walletId' => $this->walletId,
            'currency' => $this->currency->value,
            'principalMinor' => $this->principalMinor,
            'repaidMinor' => $this->repaidMinor,
            'repayments' => $this->repayments,
            'settled' => $this->settled,
        ];
    }
```

The default threshold is 50; the harness lowers it to 3 so a short test crosses it. A loan granted and then repaid three times has four events, and the test shows what a load does with them:

<!-- source: samples/lumen/tests/EventSourcing/LoanRepositoryTest.php -->
```php
    $snapshot = $this->app()->make(SnapshotStore::class)->latest('loan-r2');
    $replayed = $this->app()->make(EventStore::class)->load('loan-r2', 4)->events;
    $loan = $loans->get('loan-r2');

    expect($snapshot?->version)->toBe(3)
        ->and($snapshot?->state)->toMatchArray(['repaidMinor' => 40000, 'repayments' => 2])
        ->and(array_map(static fn (StoredEvent $event): string => $event->eventType, $replayed))->toBe(['loan.repaid'])
        ->and([$loan->repaidMinor(), $loan->outstanding()->minorUnits, $loan->committedVersion()])->toBe([60000, 30000, 4]);
```

The snapshot was taken at version 3, so loading the loan restores it and replays one event — the fourth. A snapshot is an optimization and nothing more: one that cannot be written is logged and the save stands, one whose `snapshotVersion()` no longer matches the class is ignored and the loan is replayed from its first event, so changing the snapshot's shape is a version bump, never a migration.

---

## Two writers, one version

Every event of a stream has a version — 1, 2, 3 — and the store refuses a second event at a version that is taken (`UNIQUE(aggregate_id, aggregate_version)`). That is optimistic concurrency, and it is what keeps two requests from repaying the same loan from the same stale picture of it:

<!-- source: samples/lumen/tests/EventSourcing/LoanRepositoryTest.php -->
```php
    $first = $loans->get('loan-r3');
    $second = $loans->get('loan-r3');

    $first->repay(new Money(10000, Currency::EUR));
    $loans->save($first);
    $second->repay(new Money(10000, Currency::EUR));

    expect(fn () => $loans->save($second))->toThrow(ConcurrencyException::class)
        ->and($loans->get('loan-r3')->repaidMinor())->toBe(10000);
```

Both writers loaded version 1. The first save appended version 2; the second tried to append version 2 as well and got a 409 `ConcurrencyException`. Its decision was made against a loan that no longer exists, so the honest answer is to reload and decide again — or to let the 409 reach the client, which the web layer renders as problem+json like any other conflict.

---

## One transaction with the wallet

Granting a loan pays the principal into the wallet; repaying takes the amount back out. Lumen does both in one command, so the event store and the Eloquent wallet share one `#[Transactional]` boundary:

<!-- source: samples/lumen/src/Application/Loan/RepayLoanHandler.php -->
```php
    #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")]
    #[Transactional]
    public function handle(RepayLoan $command): int
    {
        $loan = $this->loans->get($command->loanId);
        $wallet = $this->wallets->findById($loan->walletId())
            ?? throw new ResourceNotFoundException("Wallet [{$loan->walletId()}] not found");

        $amount = new Money($command->amountMinor, $loan->currency());
        $loan->repay($amount);
        $this->loans->save($loan);

        $wallet->withdraw($amount);
        $this->wallets->save($wallet);

        return $loan->outstanding()->minorUnits;
    }
```

The order is deliberate. The repayment is appended first, and only then is the wallet debited — so when the wallet cannot pay, `Wallet::withdraw()` throws *after* the event was written, and the one transaction undoes both:

<!-- source: samples/lumen/tests/EventSourcing/LoanCommandsTest.php -->
```php
    $this->commands()->send(new Withdraw($walletId, 85000));

    $caught = null;
    try {
        $this->commands()->send(new RepayLoan($loanId, 30000));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    // The repayment was appended, then the wallet refused the withdrawal: one transaction, so both are undone.
    expect($caught?->getPrevious())->toBeInstanceOf(ConflictException::class)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->count())->toBe(1)
        ->and($this->app()->make(LoanRepository::class)->get($loanId)->outstanding()->minorUnits)->toBe(90000)
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(5000);
```

The stream still holds only `loan.granted`, the loan still owes 900.00, and the wallet still holds its 50.00: nothing half-happened. The handler is guarded like Chapter 10's `Withdraw`, and it is `#[Lazy]` for the repository's reason. One more consequence of sharing the transaction: `save()` drains the loan's buffer, so its events are never dispatched a second time by `firefly/data`'s after-commit wiring (Chapter 9); the wallet's own `FundsWithdrawn` still is.

---

## Projections and their checkpoints

The events answer "what happened to this loan"; they are a poor answer to "which loans are still open". That question belongs to a read model, and a **projection** keeps one from the events. `LoanBookProjection` is a component the runner finds by itself:

<!-- source: samples/lumen/src/Application/Loan/LoanBookProjection.php -->
```php
    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return ['loan.granted', 'loan.repaid', 'loan.settled'];
    }

    public function handle(StoredEvent $event): void
    {
        $payload = $event->payload;
        $loan = DB::table(self::TABLE)->where('loan_id', $event->aggregateId);

        if ($event->eventType === 'loan.granted') {
            DB::table(self::TABLE)->insert([
                'loan_id' => $event->aggregateId,
                'wallet_id' => self::text($payload, 'walletId'),
                'currency' => self::text($payload, 'currency'),
                'principal_minor' => self::number($payload, 'principalMinor'),
                'repaid_minor' => 0,
                'outstanding_minor' => self::number($payload, 'principalMinor'),
                'repayments' => 0,
                'status' => 'open',
            ]);
        } elseif ($event->eventType === 'loan.repaid') {
            $loan->incrementEach(
                ['repaid_minor' => self::number($payload, 'amountMinor'), 'repayments' => 1],
                ['outstanding_minor' => self::number($payload, 'outstandingMinor')],
            );
        } else {
            $loan->update(['status' => 'settled']);
        }
    }
```

A projection reads the **global stream** — every event of every aggregate, in one order — from its **checkpoint**, the position it has projected up to. The test shows the gap between the two worlds and the command that closes it:

<!-- source: samples/lumen/tests/EventSourcing/LoanBookProjectionTest.php -->
```php
    // The events are stored; the loan book has not been told yet.
    expect(DB::table(LoanBookProjection::TABLE)->count())->toBe(0);

    $exit = Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('lumen.loan-book: caught-up, 2 event(s) handled, at position 2')
        ->and((array) DB::table(LoanBookProjection::TABLE)->where('loan_id', $loanId)->first())->toMatchArray([
            'wallet_id' => $walletId,
            'principal_minor' => 90000,
            'repaid_minor' => 30000,
            'outstanding_minor' => 60000,
            'repayments' => 1,
            'status' => 'open',
        ])
        ->and(DB::table(EventSourcingSchema::CHECKPOINTS)->where('projection', LoanBookProjection::NAME)->value('position'))->toEqual(2);
```

Each batch is one transaction on the checkpoint store's connection: the runner hands the projection the events it `handles()`, moves the checkpoint from the position it read to the batch's last one, and commits both. `LoanBookProjection` writes through the default connection, which is that connection while `firefly.eventsourcing.store.connection` is null, so its rows and its checkpoint commit together and every event is projected exactly once; a projection that writes anywhere else is at-least-once and must be idempotent. A batch is taken under a lease on the `DistributedLock` port (`firefly.scheduling.lock.provider`, for `firefly.eventsourcing.projection.lease-ttl`), so you can run the command on every node and only one advances each projection at a time — and the checkpoint advance is fenced, so a runner whose lease expired mid-batch rolls its batch back instead of overwriting a newer position. Every write in `handle()` names the row by its primary key, which is what lets SQL Server run it without waiting on another worker's uncommitted rows. Operators drive projections from the command line:

<!-- illustrative: the commands an operator runs in the application's directory -->
```bash
php artisan firefly:eventsourcing:projections
php artisan firefly:eventsourcing:projections:run
php artisan firefly:eventsourcing:projections:run --live
php artisan firefly:eventsourcing:projections:rebuild lumen.loan-book
```

The first lists every projection with its checkpoint, the global head and its lag; the second catches every projection up and exits, and `--live` keeps following the stream under a process supervisor; the last resets the projection and its checkpoint and replays the whole stream into it — `LoanBookProjectionTest` deletes the loan book and gets it back that way. The global stream is numbered in **commit** order, not insert order: a sequence hands out its value when a row is inserted, so a transaction holding position 10 could commit after the one holding 11, and a reader that had checkpointed 11 would skip 10 forever. The store numbers committed events in a short transaction of its own before every global read, so a reader never sees an event appear below a position it has already passed.

---

## Upcasting a renamed field

Events are immutable, so a changed shape is a new version. The first loans stored their repayments as `loan.repaid` version 1, with the amount called `amount`; the class now says `amountMinor`, like every other money field in Lumen. The stored rows are never rewritten. An `EventUpcaster` moves a version-1 payload forward each time one is read:

<!-- source: samples/lumen/src/Infrastructure/Loan/LoanRepaidV1Upcaster.php -->
```php
#[Component]
final class LoanRepaidV1Upcaster implements EventUpcaster
{
    public function eventType(): string
    {
        return 'loan.repaid';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function priority(): int
    {
        return 0;
    }

    public function upcast(StoredEvent $event): StoredEvent
    {
        $payload = $event->payload;
        $payload['amountMinor'] = $payload['amount'] ?? 0;
        unset($payload['amount']);

        return $event->upcasted('loan.repaid', 2, $payload);
    }
}
```

Upcasting happens in the store, on read, so the repository and every projection see the same version-2 event. The test writes a repayment exactly as an old deployment left it and reads it both ways:

<!-- source: samples/lumen/tests/EventSourcing/LoanRepaidUpcasterTest.php -->
```php
    // A repayment exactly as the first loans were stored: version 1, its amount still called `amount`.
    $this->app()->make(EventStore::class)->append('loan-legacy', Loan::aggregateType(), [
        new NewEvent('loan.repaid', ['loanId' => 'loan-legacy', 'amount' => 20000, 'outstandingMinor' => 30000], 1),
    ], 1);

    $loan = $loans->get('loan-legacy');
    Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect([$loan->repaidMinor(), $loan->outstanding()->minorUnits, $loan->repayments()])->toBe([20000, 30000, 1])
        ->and(DB::table(LoanBookProjection::TABLE)->where('loan_id', 'loan-legacy')->value('repaid_minor'))->toEqual(20000)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'loan-legacy')->where('event_type', 'loan.repaid')->value('event_version'))->toEqual(1);
```

The row in `firefly_eventsourcing_events` still says version 1; the loan and the loan book both read 200.00 repaid. Upcasters chain by type and version (`v1 → v2 → v3`), the highest `priority()` wins among two for the same step, and an event whose version is still not the registered one after upcasting is refused with the upcaster it is missing — the first test in that file fails exactly that way until `LoanRepaidV1Upcaster` exists.

---

## Publishing through the outbox, in the same transaction

Other services want to hear that a loan was repaid. Chapter 8's outbox already solves "publish only what committed, and never lose it"; the event store plugs into it:

<!-- source: skeleton/config/firefly.php -->
```php
'publish' => [
    'enabled' => env('FIREFLY_EVENTSOURCING_PUBLISH_ENABLED', false),
    'destination' => env('FIREFLY_EVENTSOURCING_PUBLISH_DESTINATION', 'eventsourcing.events'),
    'exclude-aggregate-types' => ['orchestration.execution'],
],
```

With `firefly.eventsourcing.publish.enabled` on and `firefly.eda.provider=database` (or `postgres`), every appended event is written to `firefly_eda_outbox` *inside the append's transaction*, with its `x-event-id`, `x-event-version`, `x-aggregate-id`, `x-aggregate-type`, `x-aggregate-version` and `x-occurred-at` headers. Lumen's outbox harness publishes loans on `lumen.loans`:

<!-- source: samples/lumen/tests/EventSourcing/LoanOutboxPublicationTest.php -->
```php
    expect(DB::table(OutboxSchema::TABLE)->where('destination', LumenEventSourcingOutboxTestCase::DESTINATION)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid'])
        ->and(DB::table(OutboxSchema::TABLE)->where('destination', 'wallet.events')->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn'])
        ->and(is_string($headers) ? json_decode($headers, true) : null)->toHaveKey('x-aggregate-id', $loanId)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(5);
```

Two sources, one table, one transaction: the loan's events come from the event store, the wallet's from `firefly/data`'s pre-commit hook, and they commit or roll back together — the second test in the file repeats the overdrawn repayment and finds only `loan.granted` in the outbox. `php artisan firefly:eda:consume` then delivers all five, at least once, exactly as in Chapter 8. The event store and the outbox must share a connection, or the boot is refused naming both keys. Under any other eda provider the events are published after the append commits, at most once; when every event must reach the broker, use the outbox.

---

## Orchestration on the event store

Chapter 9A's checkpoints went to `memory`, `cache` or three portable tables. `firefly/eventsourcing` adds a fourth persistence provider, `event-sourced`, which keeps every saga, TCC and workflow execution as an aggregate in the event store:

<!-- illustrative: an application's own .env file -->
```dotenv
FIREFLY_EVENTSOURCING_STORE=database
FIREFLY_ORCHESTRATION_PERSISTENCE=event-sourced
```

Nothing in the saga changes. Lumen runs the same payout saga on it:

<!-- source: samples/lumen/tests/EventSourcing/EventSourcedPayoutTest.php -->
```php
    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $walletId, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 20000]);
    $stream = $this->app()->make(EventStore::class)->load($result->correlationId);

    expect($result->success)->toBeTrue()
        ->and($this->app()->make(ExecutionPersistenceProvider::class))->toBeInstanceOf(EventSourcedExecutionPersistenceProvider::class)
        ->and($stream->aggregateType())->toBe('orchestration.execution')
        ->and($stream->events[0]->eventType)->toBe('orchestration.execution.started')
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('COMPLETED')
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(30000);
```

The execution's stream starts with `orchestration.execution.started` and records each checkpoint as the events that changed it, so a replay gives back exactly the state that was saved. The queries the engines run — in-flight, stale and due executions, search attributes — still read orchestration's own tables, now an *inline* projection written in the same transaction as the events; `php artisan firefly:eventsourcing:projections:rebuild orchestration.executions` writes them again from the streams, which the file's second test does after deleting them. Executions are excluded from publishing by default (`exclude-aggregate-types`).

---

## On every database

Everything above runs on the sample's SQLite `:memory:` database in the default gate, and again on real servers in the integration lanes of Chapter 12: `LoanLedgerRoundTripTest` grants, repays and settles a loan with its snapshot, its outbox rows and its loan book, and reads a version-1 repayment, on PostgreSQL, MySQL, MariaDB, SQL Server and a SQLite file; `EventSourcedPayoutRoundTripTest` does the same for the event-sourced payout. Run one lane with its DSN variable set and `composer test:integration -- samples/lumen/tests/Integration`. The commit-order numbering is what those lanes prove hardest: it uses `READ COMMITTED` on MySQL and MariaDB and `READPAST` on SQL Server so an open append is neither seen nor waited for.

!!! laravel "Laravel parity"
    Laravel's events are notifications: `Event::dispatch()` leaves nothing behind unless a listener writes it down, and an Eloquent model keeps only the latest version of its row. An audit trail is a second table you keep in step by hand. `firefly/eventsourcing` inverts that for the aggregates that need it — the events are the record, the row is a projection you can drop and rebuild — while Eloquent stays exactly what it is for everything else, in the same transaction.

---

## What you learned {.recap}

| Concept | What it does |
|---|---|
| `EventSourcedAggregateRoot` / `recordThat()` | State is the replay of recorded events; `apply…()` methods are the only writers |
| `#[EventType]` | A stable stored name and a payload version, compiled by `firefly:cache` |
| `EventSourcedRepository` | `get()` replays, `save()` appends under the loaded version — a stale writer gets a 409 `ConcurrencyException` |
| `SnapshotAware` | A snapshot every `firefly.eventsourcing.snapshot.threshold` events; a load replays only what came after it |
| `Projection` + checkpoint | A read model from the commit-ordered global stream, rows and checkpoint in one transaction, under a lease |
| `EventUpcaster` | Moves an old event version forward on read, in the store, for repositories and projections alike |
| `firefly.eventsourcing.publish.enabled` | Writes each appended event to the outbox in the append's transaction |
| `event-sourced` orchestration | Saga, TCC and workflow executions as event streams, orchestration's tables as their inline read model |

---

## Try it yourself {.exercises}

1. **Settle in one go.** Add a test to `LoanCommandsTest` that repays a whole loan in one `RepayLoan` and asserts that the single save appended two events — `loan.repaid` and `loan.settled` — then check whether that save crossed the snapshot threshold.
2. **A third version.** Imagine `loan.repaid` version 3 adds a `channel` field defaulting to `wallet`. Write the version-2-to-3 upcaster, raise the `#[EventType]` version, and confirm with `LoanRepaidUpcasterTest` that a version-1 row now travels `v1 → v2 → v3` on read.
3. **A second read model.** Write a projection that counts repayments per wallet into a table of its own, run `php artisan firefly:eventsourcing:projections:run`, and then rebuild only it with `php artisan firefly:eventsourcing:projections:rebuild` — the loan book's checkpoint does not move.
