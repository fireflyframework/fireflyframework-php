<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loan book: LoanBookProjection's read model, one row per loan, keyed by the loan id so every projection write is
 * an exact-primary-key insert or update (what SQL Server needs to never wait on another worker's uncommitted rows).
 * It holds nothing the events do not: drop it and `php artisan firefly:eventsourcing:projections:rebuild
 * lumen.loan-book` writes it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_book', function (Blueprint $table): void {
            $table->string('loan_id', 64)->primary();
            $table->string('wallet_id');
            $table->string('currency', 3);
            $table->bigInteger('principal_minor');
            $table->bigInteger('repaid_minor');
            $table->bigInteger('outstanding_minor');
            $table->integer('repayments');
            $table->string('status', 16);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_book');
    }
};
