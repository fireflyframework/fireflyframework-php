<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Domain\PreCommitEventHook;
use Firefly\Domain\AggregateRoot;
use Firefly\Domain\DomainEvent;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Support\Facades\DB;

uses(FireflyDatabaseTestCase::class);

/** A concrete AggregateRoot raising one REAL DomainEvent via the (protected) raiseEvent() API. */
function trackedAggregateRaising(DomainEvent $event): AggregateRoot
{
    return new class($event) extends AggregateRoot
    {
        public function __construct(DomainEvent $event)
        {
            $this->raiseEvent($event); // AggregateRoot::raiseEvent(DomainEvent) — verified: protected, appends to the buffer drained by pullEvents()
        }
    };
}

it('invokes the pre-commit hook synchronously DURING the tx, before the after-commit publish', function () {
    $order = [];

    $tracker = new AggregateTracker;
    $appPublisher = new class($order) implements ApplicationEventPublisher
    {
        /** @param list<string> $order */
        public function __construct(public array &$order) {}

        public function publish(object $event): void
        {
            $this->order[] = 'after-commit-publish';
        }
    };
    $hook = new class($order) implements PreCommitEventHook
    {
        /** @var list<?string> */
        public array $connections = [];

        /** @param list<string> $order */
        public function __construct(public array &$order) {}

        public function handle(object $event, ?string $connection = null): void
        {
            $this->order[] = 'in-tx-hook';
            $this->connections[] = $connection;
        }
    };

    $dispatcher = new DomainEventDispatcher($tracker, $appPublisher, $hook);
    $event = new readonly class extends DomainEvent {}; // a REAL DomainEvent subclass (readonly, to extend the readonly base)
    $tracker->track(trackedAggregateRaising($event));

    DB::transaction(function () use ($dispatcher): void {
        $dispatcher->dispatchAfterCommit(); // called inside the tx, exactly as TransactionTemplate does
        // the hook must already have fired here (synchronously); the publish is deferred to after commit.
    });

    expect($order)->toBe(['in-tx-hook', 'after-commit-publish'])
        ->and($hook->connections)->toBe([null]); // default connection threaded through (null == default)
});

it('threads the aggregate tx connection into the hook (I1 — same-tx on a NAMED connection)', function () {
    // A real NON-default sqlite :memory: connection so the dispatcher's DB::connection('audit')->afterCommit()
    // registration resolves (mirrors the sibling AfterCommitDispatchTest's 'secondary' setup).
    config(['database.connections.audit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);

    $tracker = new AggregateTracker;
    $appPublisher = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void {}
    };
    $hook = new class implements PreCommitEventHook
    {
        /** @var list<?string> */
        public array $connections = [];

        public function handle(object $event, ?string $connection = null): void
        {
            $this->connections[] = $connection;
        }
    };

    $dispatcher = new DomainEventDispatcher($tracker, $appPublisher, $hook);
    $tracker->track(trackedAggregateRaising(new readonly class extends DomainEvent {}));

    // TransactionTemplate calls dispatchAfterCommit($d->connection); a #[Transactional(connection:'audit')] method passes 'audit'.
    $dispatcher->dispatchAfterCommit('audit');

    expect($hook->connections)->toBe(['audit']); // proves the outbox write would target the aggregate's own connection
});

it('is a pure no-op path when no hook is bound (M8 behaviour unchanged)', function () {
    $order = [];
    $tracker = new AggregateTracker;
    $appPublisher = new class($order) implements ApplicationEventPublisher
    {
        /** @param list<string> $order */
        public function __construct(public array &$order) {}

        public function publish(object $event): void
        {
            $this->order[] = 'after-commit-publish';
        }
    };

    $dispatcher = new DomainEventDispatcher($tracker, $appPublisher); // NO hook
    $tracker->track(trackedAggregateRaising(new readonly class extends DomainEvent {}));

    DB::transaction(fn () => $dispatcher->dispatchAfterCommit());

    expect($order)->toBe(['after-commit-publish']);
});
