<?php

declare(strict_types=1);

use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function () {
    Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t));
});

it('writes the outbox row INSIDE the caller transaction (present after commit)', function () {
    $publisher = new PostgresEventPublisher(DB::connection(), 'firefly_eda_events');

    DB::transaction(function () use ($publisher): void {
        $publisher->publish('users', 'user.created', ['id' => 1], ['x-a' => 'b']);
    });

    // The row committed WITH the tx: it exists, PENDING, with the exact mapped columns (fails if the INSERT
    // were not enlisted in the caller's tx — nothing would be visible after commit).
    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'user.created')
        ->where('status', OutboxSchema::STATUS_PENDING)
        ->where('attempts', 0)
        ->where('payload', json_encode(['id' => 1]))
        ->where('headers', json_encode(['x-a' => 'b']))
        ->count())->toBe(1)
        ->and(DB::table(OutboxSchema::TABLE)->count())->toBe(1);
});

it('rolls the outbox row back WITH the aggregate (absent after rollback)', function () {
    $publisher = new PostgresEventPublisher(DB::connection(), 'firefly_eda_events');

    try {
        DB::transaction(function () use ($publisher): void {
            $publisher->publish('users', 'user.created', ['id' => 2]);
            throw new RuntimeException('business failure after the outbox write');
        });
    } catch (RuntimeException) {
        // expected
    }

    // The INSERT rolled back atomically with the aggregate's tx: no row survives.
    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(0);
});

it('writes the outbox row on the aggregate OWN named connection (I1)', function () {
    config(['database.connections.audit' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]]);

    Schema::connection('audit')->create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t));

    DB::connection('audit')->transaction(function (): void {
        (new PostgresEventPublisher(DB::connection('audit')))->publish('users', 'user.created', ['id' => 3]);
    });

    // The row lands on the aggregate's OWN connection ('audit')...
    expect(DB::connection('audit')->table(OutboxSchema::TABLE)
        ->where('event_type', 'user.created')
        ->where('payload', json_encode(['id' => 3]))
        ->count())->toBe(1)
        // ...and NOT on the default connection.
        ->and(DB::connection('testing')->table(OutboxSchema::TABLE)->count())->toBe(0);
});
