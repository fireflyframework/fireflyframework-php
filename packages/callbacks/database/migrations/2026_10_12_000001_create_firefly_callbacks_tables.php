<?php

declare(strict_types=1);

use Firefly\Callbacks\Schema\CallbackSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The callback subscriptions and executions tables, on firefly.callbacks.connection (null = the default).
 * Idempotent: CallbackSchema::create() skips a table that exists.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('firefly.callbacks.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        CallbackSchema::create(Schema::connection($this->getConnection()));
    }

    public function down(): void
    {
        CallbackSchema::drop(Schema::connection($this->getConnection()));
    }
};
