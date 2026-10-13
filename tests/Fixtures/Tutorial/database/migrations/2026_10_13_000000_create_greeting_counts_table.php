<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('greeting_counts', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->unsignedInteger('times');
            $table->string('last_message');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('greeting_counts');
    }
};
