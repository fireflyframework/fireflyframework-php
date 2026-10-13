<?php

declare(strict_types=1);

use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The event-sourced orchestration read model: W2's executions and search-attribute tables (and its dead-letter
 * table, which OrchestrationSchema creates with them) on the EVENT STORE's connection,
 * firefly.eventsourcing.store.connection (null = the default), so one transaction holds a checkpoint's events and its
 * read-model row. Idempotent, so it coexists with orchestration's own migration on the same connection. Loaded only
 * while firefly.orchestration.persistence.provider is event-sourced over the database store. down() leaves the
 * dead letters alone: they belong to orchestration's DLQ.
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
        OrchestrationSchema::create(Schema::connection($this->getConnection()));
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(OrchestrationSchema::SEARCH_ATTRIBUTES);
        Schema::connection($this->getConnection())->dropIfExists(OrchestrationSchema::EXECUTIONS);
    }
};
