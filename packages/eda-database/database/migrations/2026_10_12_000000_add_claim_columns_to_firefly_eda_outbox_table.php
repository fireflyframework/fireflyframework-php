<?php

declare(strict_types=1);

use Firefly\Eda\Database\Outbox\OutboxSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrades an outbox created before the lease columns existed. A fresh install already has them (the create
 * migration builds the full OutboxSchema::blueprint()), so both directions check before they act.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(OutboxSchema::TABLE) || Schema::hasColumn(OutboxSchema::TABLE, 'claimed_by')) {
            return;
        }

        Schema::table(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::claimColumns($table));
    }

    public function down(): void
    {
        if (! Schema::hasTable(OutboxSchema::TABLE) || ! Schema::hasColumn(OutboxSchema::TABLE, 'claimed_by')) {
            return;
        }

        Schema::table(OutboxSchema::TABLE, function (Blueprint $table): void {
            $table->dropIndex(OutboxSchema::CLAIM_INDEX);
            $table->dropColumn(['claimed_by', 'claimed_until']);
        });
    }
};
