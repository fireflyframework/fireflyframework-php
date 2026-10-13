<?php

declare(strict_types=1);

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ReceivedEnvelope;
use Firefly\Eda\Database\DatabaseEventConsumer;
use Firefly\Eda\Database\DatabaseEventPublisher;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\OutboxRelay;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Eda\Database\Tests\Fixtures\RecordingDownstream;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

/**
 * The portable outbox against every real server of the matrix (and a sqlite FILE): what sqlite :memory: unit tests
 * cannot show — another SESSION not seeing the row before the writer commits, the lease columns' millisecond
 * comparison on each server's own datetime type, each strategy's claim SQL running for real, and the upgrade
 * migration's DDL (add two columns and an index, then drop them) on every grammar.
 *
 * @return array{Connection, Connection} a writer and a consumer session on the same database
 */
function roundTripSessions(string $lane): array
{
    $overrides = $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('outbox-roundtrip')] : [];
    config([
        'database.connections.it_writer' => IntegrationDsn::connectionConfig($lane, $overrides),
        'database.connections.it_consumer' => IntegrationDsn::connectionConfig($lane, $overrides),
    ]);
    DB::setDefaultConnection('it_writer');

    return [DB::connection('it_writer'), DB::connection('it_consumer')];
}

/** Runs one direction of one of the package's anonymous migrations, on the default (writer) connection. */
function roundTripMigrate(string $file, string $direction = 'up'): void
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/'.$file;

    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $direction === 'down' ? $migration->down() : $migration->up();
}

function roundTripConsumer(Connection $connection, string $claimant, string $now, int $maxAttempts = 3): DatabaseEventConsumer
{
    return new DatabaseEventConsumer(
        $connection,
        OutboxClaimStrategies::resolve($connection),
        $maxAttempts,
        leaseSeconds: 60,
        claimant: $claimant,
        awaitWork: static function (int $timeoutMs): void {},
        clock: static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')),
    );
}

foreach (IntegrationDsn::lanes() as $lane) {
    it("upgrades and creates the outbox with its migrations on {$lane}", function () use ($lane): void {
        roundTripSessions($lane);
        Schema::dropIfExists(OutboxSchema::TABLE);
        Schema::create(OutboxSchema::TABLE, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('destination');
            $table->string('channel', 63);
            $table->string('event_type');
            $table->json('payload');
            $table->json('headers');
            $table->string('transaction_id')->nullable();
            $table->string('status')->default(OutboxSchema::STATUS_PENDING);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
        });

        $upgrade = '2026_10_12_000000_add_claim_columns_to_firefly_eda_outbox_table.php';
        roundTripMigrate($upgrade);
        expect(Schema::hasColumns(OutboxSchema::TABLE, ['claimed_by', 'claimed_until']))->toBeTrue();
        roundTripMigrate($upgrade, 'down');
        expect(Schema::hasColumn(OutboxSchema::TABLE, 'claimed_by'))->toBeFalse();

        Schema::drop(OutboxSchema::TABLE);
        roundTripMigrate('2026_07_27_000000_create_firefly_eda_outbox_table.php');
        roundTripMigrate($upgrade);
        expect(Schema::hasColumns(OutboxSchema::TABLE, ['claimed_by', 'claimed_until']))->toBeTrue();
        Schema::drop(OutboxSchema::TABLE);
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));

    it("writes same-transaction, leases, hands over on expiry, retries and relays on {$lane}", function () use ($lane): void {
        [$writer, $reader] = roundTripSessions($lane);
        $writer->getSchemaBuilder()->dropIfExists(OutboxSchema::TABLE);
        $writer->getSchemaBuilder()->create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));

        try {
            $publisher = new DatabaseEventPublisher($writer, new SubscriberRegistry);

            // (1) invisible to another session until the writer commits
            $writer->transaction(function () use ($publisher, $reader): void {
                $publisher->publish('orders', 'order.placed', ['n' => 1]);
                // SQL Server without READ_COMMITTED_SNAPSHOT makes a plain read WAIT on the uncommitted row (here, forever:
                // the writer is this process); READPAST reads what is committed, which is the claim being made.
                $visible = $reader->table(OutboxSchema::TABLE);
                expect(($reader->getDriverName() === 'sqlsrv' ? $visible->lock('with(readpast)') : $visible)->count())->toBe(0);
            });
            expect($reader->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(1);

            // (2) a lease keeps a second consumer out until it ends; the late first claimant settles nothing
            $first = roundTripConsumer($reader, 'a', '2026-10-12 10:00:00')->poll(10);
            expect($first)->toBeInstanceOf(ReceivedEnvelope::class)
                ->and(roundTripConsumer($reader, 'b', '2026-10-12 10:00:30')->poll(10))->toBeNull();
            $consumerB = roundTripConsumer($reader, 'b', '2026-10-12 10:01:00.500');
            $second = $consumerB->poll(10);
            assert($first instanceof ReceivedEnvelope && $second instanceof ReceivedEnvelope);
            expect($second->envelope?->payload)->toBe(['n' => 1]);
            roundTripConsumer($reader, 'a', '2026-10-12 10:01:01')->ack($first);
            expect($reader->table(OutboxSchema::TABLE)->value('status'))->toBe(OutboxSchema::STATUS_PENDING);
            $consumerB->ack($second);
            expect($reader->table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->whereNotNull('processed_at')->count())->toBe(1);

            // (3) nack -> PENDING with attempts+1, then FAILED past max attempts
            $publisher->publish('orders', 'order.updated', ['n' => 2]);
            $retrying = roundTripConsumer($reader, 'c', '2026-10-12 11:00:00', maxAttempts: 2);
            $received = $retrying->poll(10);
            assert($received instanceof ReceivedEnvelope);
            $retrying->nack($received);
            expect($reader->table(OutboxSchema::TABLE)->where('event_type', 'order.updated')->value('status'))->toBe(OutboxSchema::STATUS_PENDING);
            $again = $retrying->poll(10);
            assert($again instanceof ReceivedEnvelope);
            $retrying->nack($again);
            expect($reader->table(OutboxSchema::TABLE)->where('event_type', 'order.updated')->value('status'))->toBe(OutboxSchema::STATUS_FAILED);

            // (4) the relay forwards to a distinct downstream and refuses the writer
            $publisher->publish('billing', 'invoice.raised', ['n' => 3], ['x-c' => 'd']);
            $downstream = new RecordingDownstream;
            expect((new OutboxRelay($reader, $downstream, OutboxClaimStrategies::resolve($reader)))->relayBatch())->toBe(1)
                ->and($downstream->published[0]->payload)->toBe(['n' => 3])
                ->and($downstream->published[0]->headers)->toBe(['x-c' => 'd'])
                ->and(fn () => new OutboxRelay($reader, $publisher))->toThrow(LogicException::class);
        } finally {
            $writer->getSchemaBuilder()->dropIfExists(OutboxSchema::TABLE);
            $writer->disconnect();
            $reader->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
