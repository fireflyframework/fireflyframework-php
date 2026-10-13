<?php

declare(strict_types=1);

use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The orchestration tables (executions, search attributes, dead letters) on the connection
 * firefly.orchestration.persistence.connection names (null = the default). Idempotent.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('firefly.orchestration.persistence.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        OrchestrationSchema::create(Schema::connection($this->getConnection()));
    }

    public function down(): void
    {
        OrchestrationSchema::drop(Schema::connection($this->getConnection()));
    }
};
