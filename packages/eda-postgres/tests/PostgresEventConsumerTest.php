<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventConsumer;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(fn () => Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t)));

it('delivers each committed PENDING row exactly once and NEVER grows the outbox (B1 regression)', function () {
    $publisher = new PostgresEventPublisher(DB::connection()); // emitNotify=false on sqlite
    foreach ([1, 2, 3] as $id) {
        $publisher->publish('users', 'user.created', ['id' => $id]);
    }
    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(3);

    $registry = new SubscriberRegistry;
    $seen = [];
    $registry->subscribe('user.*', function (EventEnvelope $e) use (&$seen): void {
        $seen[] = $e->payload['id'];
    });

    $consumer = new PostgresEventConsumer(DB::connection()); // concrete sqlite Connection; getDriverName()='sqlite'
    $consumer->subscribe(['user.*']);

    $processed = (new ConsumerLoop)->run(
        $consumer,
        fn (EventEnvelope $e) => $registry->deliver($e),
        new ConsumerOptions(maxMessages: 3, pollTimeoutMs: 10),
    );

    expect($processed)->toBe(3)
        ->and($seen)->toBe([1, 2, 3])
        ->and(DB::table(OutboxSchema::TABLE)->count())->toBe(3)                                     // outbox did NOT grow
        ->and(DB::table(OutboxSchema::TABLE)->where('status', 'PUBLISHED')->count())->toBe(3);      // all marked PUBLISHED
});

it('a fresh consumer (restart) does NOT replay PUBLISHED rows (durable status window, M3)', function () {
    (new PostgresEventPublisher(DB::connection()))->publish('users', 'user.created', ['id' => 1]);
    (new ConsumerLoop)->run(new PostgresEventConsumer(DB::connection()), fn () => null, new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10));

    $seen = 0;
    $processed = (new ConsumerLoop)->run(
        new PostgresEventConsumer(DB::connection()),
        function () use (&$seen): void {
            $seen++;
        },
        new ConsumerOptions(maxMessages: 1, timeLimit: 1, pollTimeoutMs: 10),
    );

    expect($processed)->toBe(0)->and($seen)->toBe(0); // the PUBLISHED row is not re-delivered
});

it('poll() swallows a throwing NOTIFY wait and still delivers via the poll-fallback PENDING claim', function () {
    (new PostgresEventPublisher(DB::connection()))->publish('users', 'user.created', ['id' => 42]);

    $consumer = new PostgresEventConsumer(DB::connection(), 'firefly_eda_events', 3, function (int $t): void {
        throw new ErrorException('simulated deprecation-to-exception');
    }); // awaitNotification seam always throws
    $received = $consumer->poll(10);
    if (! $received instanceof ReceivedEnvelope) {
        throw new RuntimeException('Expected poll() to return a ReceivedEnvelope.');
    }

    expect($received->envelope->eventType)->toBe('user.created')
        ->and($received->envelope->destination)->toBe('users')
        ->and($received->envelope->payload)->toBe(['id' => 42]);
});
