<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use Illuminate\Database\Schema\Blueprint;

/** The ONE definition of firefly_eda_dead_letters — shared by the migration, the store and the tests. */
final class DeadLetterSchema
{
    public const string TABLE = 'firefly_eda_dead_letters';

    public static function blueprint(Blueprint $table): void
    {
        $table->string('id', 36)->primary();
        $table->string('event_id', 64)->nullable();
        $table->string('event_type');
        $table->string('destination');
        $table->json('payload');
        $table->json('headers');
        $table->string('exception_class');
        $table->text('exception_message');
        $table->dateTime('dead_lettered_at', 3);

        $table->index(['destination', 'event_type'], 'firefly_eda_dead_letters_route_idx');
        $table->index(['dead_lettered_at'], 'firefly_eda_dead_letters_at_idx');
    }
}
