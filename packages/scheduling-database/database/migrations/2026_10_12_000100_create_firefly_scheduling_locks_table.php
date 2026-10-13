<?php

declare(strict_types=1);

use Firefly\Scheduling\Database\LockSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(LockSchema::TABLE, fn (Blueprint $table) => LockSchema::blueprint($table));
    }

    public function down(): void
    {
        Schema::dropIfExists(LockSchema::TABLE);
    }
};
