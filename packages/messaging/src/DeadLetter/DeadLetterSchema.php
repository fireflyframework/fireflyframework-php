<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

use Illuminate\Database\Schema\Blueprint;

/**
 * The ONE definition of firefly_messaging_dead_letters, shared by the migration and the tests. `value` holds the raw
 * message bytes BASE64-encoded in a text column: raw bytes are not portable through PDO (pgsql bytea comes back as a
 * stream, sqlsrv needs binary-encoded bindings, MySQL text columns reject invalid UTF-8), and base64 text
 * round-trips byte for byte on every driver. `message_key` is base64 text in an unbounded column for the same
 * reason: a key is an opaque partition or routing key — a raw 16-byte UUID, a hash, an Avro-serialized key — so it
 * may be longer than a varchar and need not be valid UTF-8, and a key the column refused would turn a dead letter
 * into a lost message. `original_topic` repeats the `x-original-topic` header as a column so a replay can select by
 * it.
 */
final class DeadLetterSchema
{
    public const string TABLE = 'firefly_messaging_dead_letters';

    public static function blueprint(Blueprint $table): void
    {
        $table->string('id', 36)->primary();
        $table->string('topic');
        $table->string('original_topic')->nullable();
        $table->text('message_key')->nullable();
        $table->longText('value');
        $table->json('headers');
        $table->string('exception_class');
        $table->text('exception_message');
        $table->dateTime('dead_lettered_at', 3);

        $table->index(['topic'], 'firefly_messaging_dead_letters_topic_idx');
        $table->index(['dead_lettered_at'], 'firefly_messaging_dead_letters_at_idx');
    }
}
