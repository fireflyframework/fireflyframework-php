<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Illuminate\Database\Schema\Blueprint;

/** The ONE definition of the outbox table shape — shared by the migration, the publisher, the relay, and the tests. */
final class OutboxSchema
{
    public const string TABLE = 'firefly_eda_outbox';

    public const string STATUS_PENDING = 'PENDING';

    public const string STATUS_PUBLISHED = 'PUBLISHED';

    public const string STATUS_FAILED = 'FAILED';

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
    }
}
