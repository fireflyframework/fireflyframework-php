<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Eda\DeadLetter\DeadLetterEntry;
use Firefly\Eda\DeadLetter\DeadLetterQuery;
use Firefly\Eda\DeadLetter\DeadLetterSchema;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function (): void {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000200_create_firefly_eda_dead_letters_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $migration->up();
});

function deadLetterDatabaseStore(string $now = '2026-10-12 10:00:00.250'): DatabaseDeadLetterStore
{
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    return new DatabaseDeadLetterStore($connections, null, static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')));
}

it('keeps a dead letter durably, envelope, cause and instant included', function () {
    $envelope = new EventEnvelope('order.placed', 'orders', ['id' => 7, 'lines' => [['sku' => 'A']]], ['x-original-topic' => 'orders', 'x-exception' => 'boom'], 'evt-1');
    deadLetterDatabaseStore()->store($envelope, new LogicException('boom'));

    $entries = deadLetterDatabaseStore()->all();

    expect(Schema::hasTable(DeadLetterSchema::TABLE))->toBeTrue()
        ->and($entries)->toHaveCount(1)
        ->and($entries[0]->id)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($entries[0]->envelope->eventType)->toBe('order.placed')
        ->and($entries[0]->envelope->destination)->toBe('orders')
        ->and($entries[0]->envelope->eventId)->toBe('evt-1')
        ->and($entries[0]->envelope->payload)->toBe(['id' => 7, 'lines' => [['sku' => 'A']]])
        ->and($entries[0]->envelope->headers)->toBe(['x-original-topic' => 'orders', 'x-exception' => 'boom'])
        ->and($entries[0]->exceptionClass)->toBe(LogicException::class)
        ->and($entries[0]->exceptionMessage)->toBe('boom')
        ->and($entries[0]->deadLetteredAt?->format('Y-m-d H:i:s.v'))->toBe('2026-10-12 10:00:00.250');
});

it('selects by id, destination and event type, oldest first, and removes one entry', function () {
    deadLetterDatabaseStore('2026-10-12 10:00:02')->store(new EventEnvelope('order.paid', 'orders'), new RuntimeException('b'));
    deadLetterDatabaseStore('2026-10-12 10:00:01')->store(new EventEnvelope('order.placed', 'orders'), new RuntimeException('a'));
    deadLetterDatabaseStore('2026-10-12 10:00:03')->store(new EventEnvelope('user.created', 'users'), new RuntimeException('c'));
    $store = deadLetterDatabaseStore();

    $orders = $store->find(new DeadLetterQuery(destination: 'orders'));

    expect(array_map(static fn (DeadLetterEntry $e): string => $e->envelope->eventType, $orders))->toBe(['order.placed', 'order.paid'])
        ->and($store->find(new DeadLetterQuery(eventType: 'user.created')))->toHaveCount(1)
        ->and($store->find(new DeadLetterQuery(limit: 2)))->toHaveCount(2)
        ->and($store->find(new DeadLetterQuery(ids: [(string) $orders[1]->id]))[0]->envelope->eventType)->toBe('order.paid')
        ->and($store->remove((string) $orders[0]->id))->toBeTrue()
        ->and($store->remove((string) $orders[0]->id))->toBeFalse()
        ->and(DB::table(DeadLetterSchema::TABLE)->count())->toBe(2);
});

it('keeps insertion order for dead letters written in the same millisecond, so --limit replays the oldest', function () {
    $store = deadLetterDatabaseStore('2026-10-12 10:00:00.250');
    $types = array_map(static fn (int $i): string => "order.same-instant-{$i}", range(1, 40));
    foreach ($types as $type) {
        $store->store(new EventEnvelope($type, 'orders'), new RuntimeException('tie'));
    }

    expect(array_map(static fn (DeadLetterEntry $e): string => $e->envelope->eventType, $store->all()))->toBe($types)
        ->and($store->find(new DeadLetterQuery(destination: 'orders', limit: 1))[0]->envelope->eventType)->toBe('order.same-instant-1');
});
