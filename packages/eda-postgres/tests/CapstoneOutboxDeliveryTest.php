<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\Tests\Support\OutboxCapstoneTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(OutboxCapstoneTestCase::class);

/**
 * THE SILENT-DATA-LOSS GATE for provider=postgres.
 *
 * The defect this pins: EventListenerWiringPass subscribes every compiled #[EventListener] by calling
 * subscribe() on the RESOLVED EventPublisher — which, under provider=postgres, is PostgresEventPublisher. That
 * method used to be an empty no-op (its own docblock claimed "subscribe() feeds the shared SubscriberRegistry"
 * while the body did nothing), so the shared SubscriberRegistry the terminal consumer delivers into stayed
 * PERMANENTLY EMPTY. The consequence is worse than "no delivery": SubscriberRegistry::deliver() on an empty
 * registry returns normally, so ConsumerLoop treats the delivery as a SUCCESS and calls ack() — the row flips
 * PENDING -> PUBLISHED and is never re-selected. Every domain event published through the framework's headline
 * same-transaction outbox was durably written, drained, and thrown away, with no error anywhere.
 *
 * Nothing in the pre-existing suite could see this: every other test built its own SubscriberRegistry and
 * subscribed to it by hand, which is precisely the wiring the bug broke.
 */
it('drives the app #[EventListener] from a committed outbox row, then marks it PUBLISHED', function () {
    /** @var OutboxCapstoneTestCase $this */
    $app = $this->app();

    // Publish through the CONTAINER-RESOLVED publisher (the outbox writer), inside a business transaction.
    DB::transaction(function () use ($app): void {
        $app->make(EventPublisher::class)->publish('users', 'user.created', ['id' => 7]);
    });

    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(1);

    /** @var SubscriberRegistry $registry */
    $registry = $app->make(SubscriberRegistry::class);
    /** @var EventConsumer $consumer */
    $consumer = $app->make(EventConsumer::class);

    $processed = (new ConsumerLoop)->run(
        $consumer,
        fn (EventEnvelope $envelope) => $registry->deliver($envelope),
        new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10),
    );

    /** @var ListenerSpy $spy */
    $spy = $app->make(ListenerSpy::class);

    // The handler ACTUALLY RAN, and only then was the row acked. Before the fix the row was PUBLISHED with
    // $spy->seen === [] — delivered to nobody, acked anyway.
    expect($spy->seen)->toBe(['user.created'])
        ->and($processed)->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PUBLISHED);
});

it('populates the SHARED SubscriberRegistry the consumer reads — not a private one', function () {
    /** @var OutboxCapstoneTestCase $this */
    $app = $this->app();

    // The wiring pass subscribed onto the EventPublisher; the registry the consume command resolves must be the
    // very same object, otherwise the subscription is invisible at delivery time.
    $seen = [];
    $app->make(SubscriberRegistry::class)->deliver(new EventEnvelope('user.updated', 'users', ['id' => 1]));

    /** @var ListenerSpy $spy */
    $spy = $app->make(ListenerSpy::class);
    $seen = $spy->seen;

    expect($seen)->toBe(['user.updated']);
});

it('never acks a row whose delivery throws — it stays PENDING with attempts+1 so it is retried', function () {
    /** @var OutboxCapstoneTestCase $this */
    $app = $this->app();

    DB::transaction(function () use ($app): void {
        $app->make(EventPublisher::class)->publish('users', 'user.created', ['id' => 9]);
    });

    /** @var EventConsumer $consumer */
    $consumer = $app->make(EventConsumer::class);

    // A sink that always throws stands in for a handler that exhausted its retries with no DLQ bound.
    (new ConsumerLoop)->run(
        $consumer,
        function (): void {
            throw new RuntimeException('handler boom');
        },
        new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10),
    );

    $row = DB::table(OutboxSchema::TABLE)->first();
    if ($row === null) {
        throw new RuntimeException('Expected the committed outbox row to still exist.');
    }

    // Still claimable: a failed delivery is retried, never acked away.
    expect($row->status)->toBe(OutboxSchema::STATUS_PENDING)
        ->and($row->attempts)->toBe(1)
        ->and($row->processed_at)->toBeNull();
});

/**
 * THE SAME-TRANSACTION GUARANTEE, re-proven end to end through the CONTAINER-RESOLVED publisher.
 *
 * The other tests in this package prove atomicity against a hand-constructed PostgresEventPublisher. This one
 * proves it for the publisher a booted provider=postgres application actually uses, after the registry became a
 * constructor dependency — because the whole value of the outbox is that the business row and the outbox row share
 * one commit, and a wiring change that quietly moved the INSERT onto a different connection (or outside the
 * caller's transaction) would still pass every delivery assertion above.
 */
it('commits the business write and the outbox write together, and rolls them back together', function () {
    /** @var OutboxCapstoneTestCase $this */
    $app = $this->app();

    Schema::create('capstone_orders', function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('status');
    });

    // ROLLBACK: the business failure lands AFTER both writes, so neither may survive. A dual-write outbox (a
    // publisher on its own connection, or an after-commit hop) would leave the event row behind here.
    try {
        DB::transaction(function () use ($app): void {
            DB::table('capstone_orders')->insert(['id' => 1, 'status' => 'PLACED']);
            $app->make(EventPublisher::class)->publish('orders', 'user.placed', ['id' => 1]);

            throw new RuntimeException('business failure after both writes');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table('capstone_orders')->count())->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->count())->toBe(0);

    // COMMIT: the same two writes, no failure — both are durable, and the outbox row is claimable.
    DB::transaction(function () use ($app): void {
        DB::table('capstone_orders')->insert(['id' => 2, 'status' => 'PLACED']);
        $app->make(EventPublisher::class)->publish('orders', 'user.placed', ['id' => 2]);
    });

    expect(DB::table('capstone_orders')->where('id', 2)->count())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(1);
});
