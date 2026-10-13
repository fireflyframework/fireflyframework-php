<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\Claim\OptimisticLeaseClaim;
use Firefly\Eda\Database\Outbox\OutboxRelay;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\Database\Tests\Fixtures\RecordingDownstream;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    $publisher = new DatabaseEventPublisher(DB::connection(), new SubscriberRegistry);
    foreach ([1, 2, 3] as $n) {
        $publisher->publish('orders', 'order.placed', ['n' => $n], ['x-n' => (string) $n]);
    }
});

function relayConnection(): Connection
{
    return DB::connection();
}

function relayAt(RecordingDownstream $downstream, string $claimant, string $now, int $batch = 50, int $maxAttempts = 3): OutboxRelay
{
    return new OutboxRelay(
        relayConnection(),
        $downstream,
        new OptimisticLeaseClaim,
        $batch,
        $maxAttempts,
        leaseSeconds: 60,
        claimant: $claimant,
        clock: static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')),
    );
}

it('forwards each claimed row to the downstream, byte for byte, and marks it PUBLISHED', function () {
    $downstream = new RecordingDownstream;

    expect(relayAt($downstream, 'r', '2026-10-12 10:00:00')->relayBatch())->toBe(3)
        ->and(array_map(static fn (EventEnvelope $e): mixed => $e->payload['n'], $downstream->published))->toBe([1, 2, 3])
        ->and($downstream->published[0]->headers)->toBe(['x-n' => '1'])
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->whereNull('claimed_by')->count())->toBe(3);
});

it('splits the outbox between two relays without forwarding a row twice', function () {
    $a = new RecordingDownstream;
    $b = new RecordingDownstream;

    expect(relayAt($a, 'a', '2026-10-12 10:00:00', batch: 2)->relayBatch())->toBe(2)
        ->and(relayAt($b, 'b', '2026-10-12 10:00:00', batch: 2)->relayBatch())->toBe(1)
        ->and(count($a->published) + count($b->published))->toBe(3);
});

it('counts a failed forward as an attempt and FAILS the row past max attempts', function () {
    $downstream = new RecordingDownstream;
    $downstream->fail = true;

    relayAt($downstream, 'r', '2026-10-12 10:00:00', maxAttempts: 2)->relayBatch();
    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->where('attempts', 1)->whereNull('claimed_by')->count())->toBe(3);

    relayAt($downstream, 'r', '2026-10-12 10:00:01', maxAttempts: 2)->relayBatch();
    $row = DB::table(OutboxSchema::TABLE)->first();
    expect(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_FAILED)->count())->toBe(3)
        ->and((string) json_encode($row?->error_message))->toContain('downstream down');
});

it('refuses an outbox writer as its downstream', function () {
    expect(fn () => new OutboxRelay(relayConnection(), new DatabaseEventPublisher(relayConnection(), new SubscriberRegistry)))
        ->toThrow(LogicException::class, 'PostgresEventPublisher')
        ->and(fn () => new OutboxRelay(relayConnection(), new PostgresEventPublisher(relayConnection(), new SubscriberRegistry)))
        ->toThrow(LogicException::class, 'outbox writer');
});

it('keeps the deprecated Postgres relay constructor working', function () {
    $downstream = new RecordingDownstream;

    expect((new Firefly\Eda\Postgres\Outbox\OutboxRelay(relayConnection(), $downstream, 50, 3, true))->relayBatch())->toBe(3);
});

it('maps the deprecated useSkipLocked=false onto the portable lease, which two relays can share', function () {
    $a = new RecordingDownstream;
    $b = new RecordingDownstream;

    expect((new Firefly\Eda\Postgres\Outbox\OutboxRelay(relayConnection(), $a, 2, 3, false))->relayBatch())->toBe(2)
        ->and((new Firefly\Eda\Postgres\Outbox\OutboxRelay(relayConnection(), $b, 2, 3, false))->relayBatch())->toBe(1)
        ->and(count($a->published) + count($b->published))->toBe(3);
});
