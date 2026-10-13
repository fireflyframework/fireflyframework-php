<?php

declare(strict_types=1);

use Firefly\EventSourcing\Store\EventSourcingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The eventsourcing tables (events, the global head, snapshots, checkpoints) on the connection
 * firefly.eventsourcing.store.connection names (null = the default). Idempotent.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('firefly.eventsourcing.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        EventSourcingSchema::create(Schema::connection($this->getConnection()));
    }

    public function down(): void
    {
        EventSourcingSchema::drop(Schema::connection($this->getConnection()));
    }
};
