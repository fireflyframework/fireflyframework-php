<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\Database\Outbox\OutboxWriter;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Postgres\PostgresEventPublisher;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(fn () => Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table)));

it('writes one PENDING row that commits and rolls back with the caller\'s transaction', function () {
    $publisher = new DatabaseEventPublisher(DB::connection(), new SubscriberRegistry);

    DB::transaction(fn () => $publisher->publish('orders', 'order.placed', ['id' => 1], ['x-correlation-id' => 'c-1']));

    try {
        DB::transaction(function () use ($publisher): void {
            $publisher->publish('orders', 'order.placed', ['id' => 2]);
            throw new RuntimeException('business failure after the outbox write');
        });
    } catch (RuntimeException) {
        // expected
    }

    $row = DB::table(OutboxSchema::TABLE)->first();

    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(1)
        ->and($row?->status)->toBe(OutboxSchema::STATUS_PENDING)
        ->and($row?->transaction_id)->toBe('c-1')
        ->and($row?->channel)->toBe('firefly_eda_events')
        ->and($row?->claimed_by)->toBeNull()
        ->and(json_decode(is_string($row?->payload) ? $row->payload : '', true))->toBe(['id' => 1]);
});

it('records subscriptions on the shared registry the consumer delivers into', function () {
    $registry = new SubscriberRegistry;
    $seen = [];
    (new DatabaseEventPublisher(DB::connection(), $registry))->subscribe('order.*', function (EventEnvelope $envelope) use (&$seen): void {
        $seen[] = $envelope->eventType;
    });

    $registry->deliver(new EventEnvelope('order.placed', 'orders'));

    expect($seen)->toBe(['order.placed']);
});

it('is an outbox writer, and only its Postgres subclass notifies', function () {
    $statements = [];
    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $database = new DatabaseEventPublisher(DB::connection(), new SubscriberRegistry, 'chan');
    $database->publish('orders', 'order.placed', ['id' => 3]);

    expect($database)->toBeInstanceOf(OutboxWriter::class)
        ->and(new PostgresEventPublisher(DB::connection(), new SubscriberRegistry))->toBeInstanceOf(DatabaseEventPublisher::class)
        ->and(implode("\n", $statements))->not->toContain('pg_notify')
        ->and(DB::table(OutboxSchema::TABLE)->value('channel'))->toBe('chan');
});
