<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox;

use Illuminate\Database\Schema\Blueprint;

/**
 * The ONE definition of the outbox table shape — shared by both migrations, the publisher, the consumer, the relay
 * and the tests, under both outbox providers (`database` and `postgres`).
 *
 * `claimed_by` / `claimed_until` are the LEASE every claim strategy writes: a row is claimable while it is PENDING and
 * its lease is empty or over, and it is settled only by the claimant that holds the lease. `claimed_until` is a
 * millisecond `dateTime` in UTC written by PHP (LeaseClock), never compared with a server clock, so the comparison
 * means the same thing on every driver. Not `final`: `Firefly\Eda\Postgres\Outbox\OutboxSchema` extends it as the
 * deprecated name it used to have.
 */
class OutboxSchema
{
    public const string TABLE = 'firefly_eda_outbox';

    public const string STATUS_PENDING = 'PENDING';

    public const string STATUS_PUBLISHED = 'PUBLISHED';

    public const string STATUS_FAILED = 'FAILED';

    public const string CLAIM_INDEX = 'firefly_eda_outbox_claim_idx';

    public static function blueprint(Blueprint $table): void
    {
        $table->bigIncrements('id');
        $table->string('destination');
        $table->string('channel', 63);
        $table->string('event_type');
        $table->json('payload');
        $table->json('headers');
        $table->string('transaction_id')->nullable();
        $table->string('status')->default(self::STATUS_PENDING);
        $table->unsignedInteger('attempts')->default(0);
        $table->text('error_message')->nullable();
        $table->timestamp('created_at')->useCurrent();
        $table->timestamp('processed_at')->nullable();
        $table->timestamp('failed_at')->nullable();

        $table->index(['status', 'created_at'], 'firefly_eda_outbox_pending_idx');
        $table->index(['channel', 'status'], 'firefly_eda_outbox_channel_status_idx');

        self::claimColumns($table);
    }

    /** The lease columns: in blueprint() for a fresh table, and alone for the migration that upgrades an old one. */
    public static function claimColumns(Blueprint $table): void
    {
        $table->string('claimed_by', 100)->nullable();
        $table->dateTime('claimed_until', 3)->nullable();
        $table->index(['status', 'claimed_until'], self::CLAIM_INDEX);
    }
}
