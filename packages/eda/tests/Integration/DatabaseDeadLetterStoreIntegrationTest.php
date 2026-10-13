<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Eda\DeadLetter\DeadLetterEntry;
use Firefly\Eda\DeadLetter\DeadLetterQuery;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

uses(FireflyDatabaseTestCase::class);

/**
 * firefly_eda_dead_letters on every server: the migration's DDL, a JSON payload with nesting and non-ASCII text, the
 * millisecond instant on each server's own datetime type, oldest-first ordering (insertion order on a tied instant), selection and removal.
 *
 * Runs one direction of the package's anonymous dead-letter migration, on the default connection.
 */
function edaDeadLetterMigrate(string $direction): void
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000200_create_firefly_eda_dead_letters_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $direction === 'down' ? $migration->down() : $migration->up();
}

foreach (IntegrationDsn::lanes() as $lane) {
    it("keeps, selects and removes eda dead letters on {$lane}", function () use ($lane): void {
        config(['database.connections.it_dlq' => IntegrationDsn::connectionConfig($lane, $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('eda-dlq')] : [])]);
        DB::setDefaultConnection('it_dlq');
        edaDeadLetterMigrate('down');
        edaDeadLetterMigrate('up');

        try {
            $connections = App::make(ConnectionResolverInterface::class);
            $at = static fn (string $instant): DatabaseDeadLetterStore => new DatabaseDeadLetterStore($connections, 'it_dlq', static fn (): DateTimeImmutable => new DateTimeImmutable($instant, new DateTimeZone('UTC')));

            $payload = ['customer' => 'Ñandú Café — 東京', 'lines' => [['sku' => 'A', 'qty' => 2]], 'total' => 12.5];
            $at('2026-10-12 10:00:00.200')->store(new EventEnvelope('order.placed', 'orders', $payload, ['x-original-topic' => 'orders', 'x-exception' => 'boom'], 'evt-1'), new RuntimeException('boom'));
            $at('2026-10-12 10:00:00.100')->store(new EventEnvelope('order.paid', 'orders', ['id' => 1]), new LogicException('first'));
            $at('2026-10-12 10:00:00.300')->store(new EventEnvelope('user.created', 'users'), new RuntimeException('third'));

            $store = $at('2026-10-12 10:00:01');
            $all = $store->all();

            expect(array_map(static fn (DeadLetterEntry $entry): string => $entry->envelope->eventType, $all))->toBe(['order.paid', 'order.placed', 'user.created'])
                // toEqual, not toBe: MySQL's JSON type re-orders object keys, and order is not part of the contract.
                ->and($all[1]->envelope->payload)->toEqual($payload)
                ->and($all[1]->envelope->headers)->toEqual(['x-original-topic' => 'orders', 'x-exception' => 'boom'])
                ->and($all[1]->envelope->eventId)->toBe('evt-1')
                ->and($all[1]->deadLetteredAt?->format('Y-m-d H:i:s.v'))->toBe('2026-10-12 10:00:00.200')
                ->and($store->find(new DeadLetterQuery(destination: 'orders', eventType: 'order.placed')))->toHaveCount(1)
                ->and($store->find(new DeadLetterQuery(limit: 2)))->toHaveCount(2)
                ->and($store->remove((string) $all[0]->id))->toBeTrue()
                ->and($store->find(new DeadLetterQuery(ids: [(string) $all[0]->id])))->toBe([])
                ->and($store->all())->toHaveCount(2);

            // A tie on the instant: the time-ordered id keeps insertion order under this server's collation.
            $tied = $at('2026-10-12 10:00:02.000');
            $types = array_map(static fn (int $i): string => "tie.{$i}", range(1, 20));
            foreach ($types as $type) {
                $tied->store(new EventEnvelope($type, 'ties'), new RuntimeException('tie'));
            }
            expect(array_map(static fn (DeadLetterEntry $entry): string => $entry->envelope->eventType, $store->find(new DeadLetterQuery(destination: 'ties'))))->toBe($types);
        } finally {
            edaDeadLetterMigrate('down');
            DB::connection('it_dlq')->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
