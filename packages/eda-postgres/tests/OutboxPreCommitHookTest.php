<?php

declare(strict_types=1);

use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Eda\Postgres\Outbox\OutboxPreCommitHook;
use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Firefly\Eda\Postgres\Tests\Fixtures\OutboxSampleEvent;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function () {
    Schema::create(OutboxSchema::TABLE, fn (Blueprint $t) => OutboxSchema::blueprint($t));
});

/**
 * The hook is the domain->outbox bridge. It must map a DomainEvent EXACTLY as the after-commit
 * EdaCommandEventPublisher does — per-event destination from the destinations map, x-correlation-id ->
 * transaction_id, eventType = concrete short name, payload = public props — and it must write INSIDE the aggregate's
 * open transaction (I1).
 */
it('maps a DomainEvent into a same-tx outbox row with per-event destination + correlation id', function () {
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    $correlation = new CorrelationContext;
    $correlation->begin('corr-42');

    $hook = new OutboxPreCommitHook(
        $connections,
        'firefly_eda_events',
        'cqrs.events',
        [OutboxSampleEvent::class => 'orders.events'],
        $correlation,
    );

    DB::transaction(function () use ($hook): void {
        $hook->handle(new OutboxSampleEvent(7, 'PLACED'), null);
    });

    // The fixed mapped columns land on exactly one committed row (fails if the write were not in-tx).
    expect(DB::table(OutboxSchema::TABLE)
        ->where('event_type', 'OutboxSampleEvent')
        ->where('destination', 'orders.events')
        ->where('channel', 'firefly_eda_events')
        ->where('transaction_id', 'corr-42')
        ->where('headers', json_encode(['x-correlation-id' => 'corr-42']))
        ->count())->toBe(1);

    // Payload carries the event's public props (eventId/occurredAt are also present but non-deterministic).
    $payload = DB::table(OutboxSchema::TABLE)->where('event_type', 'OutboxSampleEvent')->value('payload');
    $decoded = json_decode(is_string($payload) ? $payload : '', true);
    if (! is_array($decoded)) {
        throw new RuntimeException('Expected the outbox payload column to decode to a json object.');
    }

    expect($decoded['orderId'])->toBe(7)
        ->and($decoded['status'])->toBe('PLACED');
});

it('rolls the mapped outbox row back with the aggregate (same-tx)', function () {
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    $hook = new OutboxPreCommitHook($connections, 'firefly_eda_events', 'cqrs.events', [], null);

    try {
        DB::transaction(function () use ($hook): void {
            $hook->handle(new OutboxSampleEvent(8, 'PLACED'), null);
            throw new RuntimeException('business failure after the in-tx outbox write');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(0);
});

it('ignores non-DomainEvent objects (handled by the generic after-commit dispatch)', function () {
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    $hook = new OutboxPreCommitHook($connections, 'firefly_eda_events', 'cqrs.events', [], null);

    DB::transaction(function () use ($hook): void {
        $hook->handle(new stdClass, null);
    });

    expect(DB::table(OutboxSchema::TABLE)->count())->toBe(0);
});
