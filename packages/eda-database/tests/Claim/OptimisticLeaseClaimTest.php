<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\Claim\ClaimableRows;
use Firefly\Eda\Database\Outbox\Claim\OptimisticLeaseClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaim;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function (): void {
    Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    foreach (range(1, 5) as $n) {
        DB::table(OutboxSchema::TABLE)->insert([
            'destination' => 'orders', 'channel' => 'firefly_eda_events', 'event_type' => 'order.placed',
            'payload' => json_encode(['n' => $n], JSON_THROW_ON_ERROR), 'headers' => '{}',
            'status' => OutboxSchema::STATUS_PENDING, 'attempts' => 0,
        ]);
    }
});

function optimisticClaimAt(string $claimant, int $limit, string $now): OutboxClaim
{
    return OutboxClaim::at($claimant, $limit, new DateTimeImmutable($now, new DateTimeZone('UTC')), 60);
}

function optimisticConnection(): Connection
{
    return DB::connection();
}

it('leases rows to one claimant at a time, and hands an ended lease to the next', function () {
    $strategy = new OptimisticLeaseClaim;

    expect($strategy->claim(optimisticConnection(), optimisticClaimAt('a', 3, '2026-10-12 10:00:00')))->toBe([1, 2, 3])
        ->and($strategy->claim(optimisticConnection(), optimisticClaimAt('b', 3, '2026-10-12 10:00:01')))->toBe([4, 5])
        ->and($strategy->claim(optimisticConnection(), optimisticClaimAt('c', 3, '2026-10-12 10:00:30')))->toBe([])
        // a's lease (until 10:01:00.000) is over at 10:01:00.001; b's (until 10:01:01) is not.
        ->and($strategy->claim(optimisticConnection(), optimisticClaimAt('c', 10, '2026-10-12 10:01:00.001')))->toBe([1, 2, 3])
        ->and(DB::table(OutboxSchema::TABLE)->where('claimed_by', 'c')->count())->toBe(3)
        ->and(DB::table(OutboxSchema::TABLE)->where('id', 1)->value('claimed_until'))->toBe('2026-10-12 10:02:00.001');
});

it('loses a row another claimant leased between its read and its conditional update', function () {
    $intruded = false;
    optimisticConnection()->beforeExecuting(static function (string $query) use (&$intruded): void {
        if (! $intruded && str_starts_with($query, 'update')) {
            $intruded = true;
            DB::table(OutboxSchema::TABLE)->where('id', 1)->update(['claimed_by' => 'intruder', 'claimed_until' => '2999-01-01 00:00:00.000']);
        }
    });

    expect((new OptimisticLeaseClaim)->claim(optimisticConnection(), optimisticClaimAt('a', 3, '2026-10-12 10:00:00')))->toBe([2, 3])
        ->and(DB::table(OutboxSchema::TABLE)->where('id', 1)->value('claimed_by'))->toBe('intruder');
});

it('never offers a row that is no longer PENDING, and settles only the claimant\'s own rows', function () {
    DB::table(OutboxSchema::TABLE)->where('id', 2)->update(['status' => OutboxSchema::STATUS_PUBLISHED]);
    $claim = optimisticClaimAt('a', 5, '2026-10-12 10:00:00');

    $ids = (new OptimisticLeaseClaim)->claim(optimisticConnection(), $claim);

    expect($ids)->toBe([1, 3, 4, 5])
        ->and(ClaimableRows::load(optimisticConnection(), $ids, 'a')->pluck('id')->all())->toBe([1, 3, 4, 5])
        ->and(ClaimableRows::load(optimisticConnection(), $ids, 'b')->all())->toBe([])
        ->and(ClaimableRows::owned(optimisticConnection(), 1, 'b')->count())->toBe(0)
        ->and(ClaimableRows::owned(optimisticConnection(), 1, 'a')->count())->toBe(1);
});
