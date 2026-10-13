<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\OutboxRow;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

/** Run one direction of one of the package's anonymous migrations. */
function outboxSchemaMigrate(string $file, string $direction = 'up'): void
{
    $migration = require dirname(__DIR__).'/database/migrations/'.$file;

    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $direction === 'down' ? $migration->down() : $migration->up();
}

it('creates the outbox with the lease columns a claim writes', function () {
    outboxSchemaMigrate('2026_07_27_000000_create_firefly_eda_outbox_table.php');

    expect(Schema::hasColumns(OutboxSchema::TABLE, ['id', 'destination', 'channel', 'event_type', 'payload', 'headers', 'transaction_id', 'status', 'attempts', 'error_message', 'created_at', 'processed_at', 'failed_at', 'claimed_by', 'claimed_until']))->toBeTrue();

    // The second migration is a no-op on a table that already has the columns — a fresh install runs both.
    outboxSchemaMigrate('2026_10_12_000000_add_claim_columns_to_firefly_eda_outbox_table.php');
    expect(Schema::hasColumn(OutboxSchema::TABLE, 'claimed_by'))->toBeTrue();
});

it('adds the lease columns to an outbox created before them, and takes them away again', function () {
    // The pre-W1 shape, as an application that migrated with 26.10.1 has it.
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

    outboxSchemaMigrate('2026_10_12_000000_add_claim_columns_to_firefly_eda_outbox_table.php');
    expect(Schema::hasColumns(OutboxSchema::TABLE, ['claimed_by', 'claimed_until']))->toBeTrue();

    outboxSchemaMigrate('2026_10_12_000000_add_claim_columns_to_firefly_eda_outbox_table.php', 'down');
    expect(Schema::hasColumn(OutboxSchema::TABLE, 'claimed_by'))->toBeFalse();
});

it('keeps the old Postgres FQCNs working as deprecated subclasses', function () {
    expect(new Firefly\Eda\Postgres\Outbox\OutboxSchema)->toBeInstanceOf(OutboxSchema::class)
        ->and(Firefly\Eda\Postgres\Outbox\OutboxSchema::TABLE)->toBe('firefly_eda_outbox')
        ->and(new Firefly\Eda\Postgres\Outbox\OutboxRow)->toBeInstanceOf(OutboxRow::class)
        ->and(Firefly\Eda\Postgres\Outbox\OutboxRow::asMap('{"a":1}'))->toBe(['a' => 1]);
});
