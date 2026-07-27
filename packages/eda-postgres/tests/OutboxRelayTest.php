<?php

declare(strict_types=1);

use Firefly\Eda\Postgres\Outbox\OutboxRelay;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Eda\Postgres\Tests\Fixtures\SpyDownstreamPublisher;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(fn () => Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t)));

it('claims PENDING rows, publishes them, and marks them PUBLISHED', function () {
    (new PostgresEventPublisher(DB::connection()))->publish('users', 'user.created', ['id' => 1]);
    $spy = new SpyDownstreamPublisher;

    $count = (new OutboxRelay(DB::connection(), $spy))->relayBatch();

    expect($count)->toBe(1)
        ->and($spy->published[0]->eventType)->toBe('user.created')
        ->and(DB::table(OutboxSchema::TABLE)->value('status'))->toBe('PUBLISHED');
});

it('is idempotent — a PUBLISHED row is never re-published', function () {
    (new PostgresEventPublisher(DB::connection()))->publish('users', 'user.created', ['id' => 1]);
    $spy = new SpyDownstreamPublisher;
    $relay = new OutboxRelay(DB::connection(), $spy);

    $relay->relayBatch();
    $second = $relay->relayBatch();

    expect($second)->toBe(0)->and($spy->published)->toHaveCount(1);
});

it('increments attempts and marks FAILED past maxAttempts', function () {
    (new PostgresEventPublisher(DB::connection()))->publish('users', 'user.created', ['id' => 1]);
    $spy = new SpyDownstreamPublisher;
    $spy->fail = true;
    $relay = new OutboxRelay(DB::connection(), $spy, batchSize: 50, maxAttempts: 2);

    $relay->relayBatch(); // attempt 1
    $relay->relayBatch(); // attempt 2 -> FAILED

    $row = DB::table(OutboxSchema::TABLE)->first();
    if ($row === null) {
        throw new RuntimeException('Expected the seeded outbox row to still exist.');
    }
    expect($row->attempts)->toBe(2)->and($row->status)->toBe('FAILED')
        ->and($row->error_message)->toContain('downstream down');
});

it('REFUSES a PostgresEventPublisher downstream (B1 — no relay self-reference / re-insert loop)', function () {
    expect(fn () => new OutboxRelay(DB::connection(), new PostgresEventPublisher(DB::connection())))
        ->toThrow(LogicException::class, 'PostgresEventPublisher');
});
