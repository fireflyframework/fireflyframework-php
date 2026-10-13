<?php

declare(strict_types=1);

use Firefly\Webhooks\Idempotency\WebhookSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The webhook receipts table, on the connection firefly.webhooks.idempotency.connection names (null = the default).
 * Idempotent: WebhookSchema::create() does nothing when the table exists.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('firefly.webhooks.idempotency.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        WebhookSchema::create(Schema::connection($this->getConnection()));
    }

    public function down(): void
    {
        WebhookSchema::drop(Schema::connection($this->getConnection()));
    }
};
