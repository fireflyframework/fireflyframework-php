<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\Database\DatabaseEventConsumer;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\Claim\OptimisticLeaseClaim;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\PostgresEventConsumer;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(fn () => Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table)));

function consumerConnection(): Connection
{
    return DB::connection();
}

/** A consumer named $claimant whose clock reads $now (UTC) — lease expiry without sleeping. */
function consumerAt(string $claimant, string $now, int $maxAttempts = 3): DatabaseEventConsumer
{
    return new DatabaseEventConsumer(
        consumerConnection(),
        new OptimisticLeaseClaim,
        $maxAttempts,
        leaseSeconds: 60,
        claimant: $claimant,
        awaitWork: static function (int $timeoutMs): void {},
        clock: static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')),
    );
}

function consumerPublish(int $id): void
{
    (new DatabaseEventPublisher(consumerConnection(), new SubscriberRegistry))->publish('users', 'user.created', ['id' => $id]);
}

it('delivers each committed row exactly once through ConsumerLoop and never grows the outbox', function () {
    foreach ([1, 2, 3] as $id) {
        consumerPublish($id);
    }
    $registry = new SubscriberRegistry;
    $seen = [];
    $registry->subscribe('user.*', function (EventEnvelope $envelope) use (&$seen): void {
        $seen[] = $envelope->payload['id'];
    });

    $processed = (new ConsumerLoop)->run(consumerAt('a', '2026-10-12 10:00:00'), fn (EventEnvelope $e) => $registry->deliver($e), new ConsumerOptions(maxMessages: 3, pollTimeoutMs: 10));

    expect($processed)->toBe(3)
        ->and($seen)->toBe([1, 2, 3])
        ->and(DB::table(OutboxSchema::TABLE)->count())->toBe(3)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->whereNull('claimed_by')->count())->toBe(3);
});

it('keeps a claimed row from a second consumer until the lease ends, then hands it over', function () {
    consumerPublish(7);

    $first = consumerAt('a', '2026-10-12 10:00:00')->poll(10);
    expect($first)->toBeInstanceOf(ReceivedEnvelope::class)
        ->and(consumerAt('b', '2026-10-12 10:00:59')->poll(10))->toBeNull();

    // a "crashed" without acking; at 10:01:00.001 a's lease is over and b takes the row.
    $consumerB = consumerAt('b', '2026-10-12 10:01:00.001');
    $second = $consumerB->poll(10);
    assert($first instanceof ReceivedEnvelope && $second instanceof ReceivedEnvelope);

    // a comes back and acks: its lease is gone, so nothing happens; b's ack settles the row.
    consumerAt('a', '2026-10-12 10:01:01')->ack($first);
    expect(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PENDING);

    $consumerB->ack($second);
    expect(DB::table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PUBLISHED);
});

it('nacks back to PENDING with the lease cleared, and FAILS past max attempts', function () {
    consumerPublish(8);
    $consumer = consumerAt('a', '2026-10-12 10:00:00', maxAttempts: 2);

    $received = $consumer->poll(10);
    assert($received instanceof ReceivedEnvelope);
    $consumer->nack($received);
    $row = DB::table(OutboxSchema::TABLE)->first();
    expect($row?->status)->toBe(OutboxSchema::STATUS_PENDING)
        ->and($row?->attempts)->toEqual(1)
        ->and($row?->claimed_by)->toBeNull();

    // Retried at once (no lease left to wait out), and the second failure is final.
    $again = $consumer->poll(10);
    assert($again instanceof ReceivedEnvelope);
    $consumer->nack($again);
    $row = DB::table(OutboxSchema::TABLE)->first();
    expect($row?->status)->toBe(OutboxSchema::STATUS_FAILED)
        ->and($row?->failed_at)->not->toBeNull()
        ->and($row?->error_message)->toBe('in-process handler failed');
});

it('hands an undecodable row over as poison, which the loop dead-letters as FAILED without retry', function () {
    DB::table(OutboxSchema::TABLE)->insert([
        'destination' => 'users', 'channel' => 'firefly_eda_events', 'event_type' => 'user.created',
        'payload' => '{not json', 'headers' => '{}', 'status' => OutboxSchema::STATUS_PENDING, 'attempts' => 0,
    ]);

    $processed = (new ConsumerLoop)->run(consumerAt('a', '2026-10-12 10:00:00'), fn () => null, new ConsumerOptions(maxMessages: 1, pollTimeoutMs: 10));
    $row = DB::table(OutboxSchema::TABLE)->first();

    expect($processed)->toBe(1)
        ->and($row?->status)->toBe(OutboxSchema::STATUS_FAILED)
        ->and($row?->error_message)->toStartWith('undecodable outbox row:');
});

it('waits for work only when there was nothing to claim', function () {
    $waits = [];
    $consumer = new DatabaseEventConsumer(consumerConnection(), new OptimisticLeaseClaim, awaitWork: static function (int $timeoutMs) use (&$waits): void {
        $waits[] = $timeoutMs;
    });

    expect($consumer->poll(25))->toBeNull()->and($waits)->toBe([25]);

    consumerPublish(9);
    expect($consumer->poll(25))->toBeInstanceOf(ReceivedEnvelope::class)->and($waits)->toBe([25]);
});

it('keeps PostgresEventConsumer\'s constructor and makes it a database consumer', function () {
    $consumer = new PostgresEventConsumer(consumerConnection(), 'firefly_eda_events', 3, static function (int $timeoutMs): void {});

    expect($consumer)->toBeInstanceOf(DatabaseEventConsumer::class)
        ->and($consumer->claimant())->toMatch('/:\d+:[0-9a-f]{8}$/');
});
