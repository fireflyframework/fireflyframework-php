<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\EventSourcing\Event\EventMetadata;
use Firefly\EventSourcing\Publishing\AfterCommitEventStorePublisher;
use Firefly\EventSourcing\Publishing\OutboxEventStorePublisher;
use Firefly\EventSourcing\Store\DatabaseEventStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\MemoryEventStore;
use Firefly\EventSourcing\Tests\Support\EventSourcingDatabase;
use Firefly\EventSourcing\Tests\Support\EventStoreContract;
use Firefly\Testing\Double\RecordingEventPublisher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Schema\Blueprint;

function outboxConnection(): Connection
{
    $connection = EventSourcingDatabase::fresh(EventSourcingDatabase::sqlite());
    $connection->getSchemaBuilder()->create(OutboxSchema::TABLE, static fn (Blueprint $table) => OutboxSchema::blueprint($table));
    $connection->setTransactionManager(new DatabaseTransactionsManager);

    return $connection;
}

it('writes one outbox row per appended event inside the append, and none when the caller rolls back', function (): void {
    $connection = outboxConnection();
    $outbox = new DatabaseEventPublisher($connection, new SubscriberRegistry);
    $store = new DatabaseEventStore($connection, publisher: new OutboxEventStorePublisher($outbox, 'orders', ['orchestration.execution']));

    $store->append('o-1', 'order', [EventStoreContract::event(metadata: new EventMetadata('corr-1')), EventStoreContract::event('order.paid')], 0);
    try {
        $connection->transaction(static function () use ($store): void {
            $store->append('o-2', 'order', [EventStoreContract::event()], 0);
            throw new RuntimeException('the command failed after appending');
        });
    } catch (RuntimeException) {
    }
    $store->append('x-1', 'orchestration.execution', [EventStoreContract::event('orchestration.execution.started')], 0);

    $rows = $connection->table(OutboxSchema::TABLE)->orderBy('id')->get(['destination', 'event_type', 'transaction_id', 'headers'])->all();
    $first = (array) $rows[0];
    /** @var array<string, string> $headers */
    $headers = json_decode(is_string($first['headers'] ?? null) ? $first['headers'] : '{}', true, flags: JSON_THROW_ON_ERROR);

    expect(array_map(static fn (object $row): array => [((array) $row)['destination'], ((array) $row)['event_type']], $rows))->toBe([['orders', 'order.placed'], ['orders', 'order.paid']])
        ->and($first['transaction_id'] ?? null)->toBe('corr-1')
        ->and([$headers['x-aggregate-id'], $headers['x-aggregate-type'], $headers['x-aggregate-version'], $headers['x-event-version']])->toBe(['o-1', 'order', '1', '1'])
        ->and($connection->table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'o-2')->count())->toBe(0);
});

it('publishes after commit, at once outside a transaction, and never for a rolled-back append', function (): void {
    $connection = outboxConnection();
    $recorder = new RecordingEventPublisher;
    $store = new DatabaseEventStore($connection, publisher: new AfterCommitEventStorePublisher($recorder, 'orders'));

    $store->append('o-1', 'order', [EventStoreContract::event()], 0);
    $atOnce = count($recorder->published);

    $insideCount = null;
    $connection->transaction(static function () use ($store, $recorder, &$insideCount): void {
        $store->append('o-2', 'order', [EventStoreContract::event()], 0);
        $insideCount = count($recorder->published);
    });
    $afterCommit = count($recorder->published);

    try {
        $connection->transaction(static function () use ($store): void {
            $store->append('o-3', 'order', [EventStoreContract::event()], 0);
            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect([$atOnce, $insideCount, $afterCommit, count($recorder->published)])->toBe([1, 1, 2, 2])
        ->and($recorder->published[1]['headers']['x-aggregate-id'])->toBe('o-2')
        ->and($recorder->published[1]['destination'])->toBe('orders');
});

it('publishes once from the memory store as well, whichever adapter is bound', function (): void {
    $inTransaction = new RecordingEventPublisher;
    $afterCommit = new RecordingEventPublisher;
    (new MemoryEventStore(publisher: new OutboxEventStorePublisher($inTransaction, 'd')))->append('o-1', 'order', [EventStoreContract::event()], 0);
    (new MemoryEventStore(publisher: new AfterCommitEventStorePublisher($afterCommit, 'd')))->append('o-1', 'order', [EventStoreContract::event()], 0);

    expect([count($inTransaction->published), count($afterCommit->published)])->toBe([1, 1]);
});

it('publishes the event\'s own stream position even when its metadata carries headers copied from the event that caused it', function (): void {
    $recorder = new RecordingEventPublisher;
    $store = new MemoryEventStore(publisher: new AfterCommitEventStorePublisher($recorder, 'orders'));
    // Metadata rebuilt from an inbound eda envelope keeps that envelope's positional headers as extras.
    $inbound = EventMetadata::fromHeaders(['x-correlation-id' => 'corr-9', 'x-event-id' => 'cause-event', 'x-aggregate-id' => 'cause-1', 'x-aggregate-version' => '7', 'x-tenant' => 'kept']);

    [$stored] = $store->append('o-1', 'order', [EventStoreContract::event(metadata: $inbound)], 0);
    $headers = $recorder->published[0]['headers'];

    expect([$headers['x-event-id'], $headers['x-aggregate-id'], $headers['x-aggregate-version']])->toBe([$stored->eventId, 'o-1', '1'])
        ->and([$headers['x-correlation-id'], $headers['x-tenant']])->toBe(['corr-9', 'kept']);
});
