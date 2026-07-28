<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('owner_id');
            $table->string('currency');
            $table->integer('balance_minor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
