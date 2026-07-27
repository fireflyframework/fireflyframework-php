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

it('maps destination->channel and stores headers + transaction_id', function () {
    (new PostgresEventPublisher(DB::connection(), 'firefly_eda_events'))
        ->publish('order.events', 'order.created', ['id' => 9], ['x-correlation-id' => 'c1']);

    // Every mapped column present on exactly one row: destination verbatim, channel from the ctor, headers as a
    // json object, and transaction_id derived from x-correlation-id.
    expect(DB::table(OutboxSchema::TABLE)
        ->where('destination', 'order.events')
        ->where('channel', 'firefly_eda_events')
        ->where('event_type', 'order.created')
        ->where('transaction_id', 'c1')
        ->where('payload', json_encode(['id' => 9]))
        ->where('headers', json_encode(['x-correlation-id' => 'c1']))
        ->count())->toBe(1);
});

it('records an empty headers map as a json object, not an array, with a null transaction_id', function () {
    (new PostgresEventPublisher(DB::connection()))
        ->publish('order.events', 'order.created', ['id' => 9]);

    // Empty headers must serialize to `{}` (object) not `[]` (array) so the jsonb column stays object-typed.
    expect(DB::table(OutboxSchema::TABLE)
        ->where('headers', '{}')
        ->whereNull('transaction_id')
        ->count())->toBe(1);
});
