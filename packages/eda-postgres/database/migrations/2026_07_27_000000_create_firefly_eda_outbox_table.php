<?php

declare(strict_types=1);

use Firefly\Eda\Postgres\Outbox\OutboxSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }

    public function down(): void
    {
        Schema::dropIfExists(OutboxSchema::TABLE);
    }
};
