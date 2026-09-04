<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Domain\AggregateRoot;
use Firefly\Domain\DomainEvent;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Postgres\Outbox\OutboxPreCommitHook;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(fn () => Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t)));

it('writes EXACTLY ONE PENDING outbox row through the real dispatcher->hook path (I2)', function () {
    /** @var ConnectionResolverInterface $resolver */
    $resolver = App::make(ConnectionResolverInterface::class);

    $hook = new OutboxPreCommitHook($resolver, new SubscriberRegistry, 'firefly_eda_events', 'cqrs.events', [], new CorrelationContext);
    $afterCommit = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void {} // postgres mode NoOps the eda after-commit leg -> no second write
    };

    $tracker = new AggregateTracker;
    $dispatcher = new DomainEventDispatcher($tracker, $afterCommit, $hook);
    $aggregate = new class(new readonly class extends DomainEvent {}) extends AggregateRoot
    {

        public function __construct(DomainEvent $e)
        {
            $this->raiseEvent($e);
        }
    };

    DB::transaction(function () use ($dispatcher, $tracker, $aggregate): void {
        $tracker->track($aggregate);
        $dispatcher->dispatchAfterCommit(); // exactly as TransactionTemplate calls it, inside the tx
    });

    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe('PENDING');
});
