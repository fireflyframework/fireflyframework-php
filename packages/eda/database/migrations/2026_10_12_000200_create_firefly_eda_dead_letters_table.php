<?php

declare(strict_types=1);

use Firefly\Eda\DeadLetter\DeadLetterSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(DeadLetterSchema::TABLE, fn (Blueprint $table) => DeadLetterSchema::blueprint($table));
    }

    public function down(): void
    {
        Schema::dropIfExists(DeadLetterSchema::TABLE);
    }
};
