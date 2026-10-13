<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\DatabaseDeadLetterStore;
use Firefly\Messaging\DeadLetter\DeadLetterEntry;
use Firefly\Messaging\DeadLetter\DeadLetterQuery;
use Firefly\Messaging\Message;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

uses(FireflyDatabaseTestCase::class);

/**
 * firefly_messaging_dead_letters on every server — above all, arbitrary BYTES in the value and the key round-trip
 * unchanged (base64), and dead letters written in the same millisecond come back in insertion order.
 *
 * Runs one direction of the package's anonymous dead-letter migration, on the default connection.
 */
function messagingDeadLetterMigrate(string $direction): void
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000300_create_firefly_messaging_dead_letters_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $direction === 'down' ? $migration->down() : $migration->up();
}

foreach (IntegrationDsn::lanes() as $lane) {
    it("keeps raw bytes and selects messaging dead letters on {$lane}", function () use ($lane): void {
        config(['database.connections.it_dlq' => IntegrationDsn::connectionConfig($lane, $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('messaging-dlq')] : [])]);
        DB::setDefaultConnection('it_dlq');
        messagingDeadLetterMigrate('down');
        messagingDeadLetterMigrate('up');

        try {
            $connections = App::make(ConnectionResolverInterface::class);
            $store = new DatabaseDeadLetterStore($connections, 'it_dlq');
            $bytes = random_bytes(512)."\x00\xFF\xC3\x28";

            // An opaque key: longer than any varchar, and not valid UTF-8 (a raw UUID, a hash, an Avro key).
            $key = str_repeat('k', 300)."\xFF\x00k";

            $store->store(new Message('ledger.DLT', $bytes, $key, ['x-original-topic' => 'ledger', 'x-exception' => 'boom']), new RuntimeException('boom'));
            $store->store(new Message('audit.DLT', 'plain', null, ['x-original-topic' => 'audit']), new RuntimeException('x'));

            $ledger = $store->find(new DeadLetterQuery(topic: 'ledger'));

            expect($ledger)->toHaveCount(1)
                ->and($ledger[0]->message->value)->toBe($bytes)
                ->and($ledger[0]->message->key)->toBe($key)
                ->and($ledger[0]->message->topic)->toBe('ledger.DLT')
                ->and($store->find(new DeadLetterQuery(topic: 'audit.DLT'))[0]->message->key ?? 'missing')->toBe('missing')
                ->and($store->remove((string) $ledger[0]->id))->toBeTrue()
                ->and($store->all())->toHaveCount(1);

            // A tie on the instant: the time-ordered id keeps insertion order under this server's collation.
            $tied = new DatabaseDeadLetterStore($connections, 'it_dlq', static fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-12 10:00:00.000', new DateTimeZone('UTC')));
            $values = array_map(static fn (int $i): string => "tie-{$i}", range(1, 20));
            foreach ($values as $value) {
                $tied->store(new Message('ties.DLT', $value, null, ['x-original-topic' => 'ties']), new RuntimeException('tie'));
            }
            expect(array_map(static fn (DeadLetterEntry $entry): string => $entry->message->value, $store->find(new DeadLetterQuery(topic: 'ties'))))->toBe($values);
        } finally {
            messagingDeadLetterMigrate('down');
            DB::connection('it_dlq')->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
