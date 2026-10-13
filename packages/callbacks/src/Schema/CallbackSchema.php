<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\SqlServerConnection;

/**
 * The two callbacks tables. Portable by construction, like WebhookSchema in firefly/webhooks: id columns are
 * case-sensitive (binary collation on MySQL/MariaDB and SQL Server), times are unix-second integers, lists and maps
 * are JSON in text columns (no driver's JSON type, so the same bytes round-trip everywhere), and create() is
 * idempotent.
 *
 *   firefly_callback_subscriptions — id PK, name, url, event_types (JSON list), secret, headers (JSON object),
 *     active, max_attempts, created_at, updated_at.
 *   firefly_callback_executions — id PK (sha256 of subscription id + event id, so a redelivered event maps to the
 *     same row), subscription_id, event_id, event_type, url, body (the exact bytes sent), status, attempts,
 *     response_status, last_error, created_at, updated_at; indexed for "recent failures".
 */
final class CallbackSchema
{
    public const string SUBSCRIPTIONS = 'firefly_callback_subscriptions';

    public const string EXECUTIONS = 'firefly_callback_executions';

    public static function create(Builder $schema): void
    {
        $connection = $schema->getConnection();

        if (! $schema->hasTable(self::SUBSCRIPTIONS)) {
            $schema->create(self::SUBSCRIPTIONS, static function (Blueprint $table) use ($connection): void {
                self::binary($table->string('id', 36), $connection);
                $table->string('name', 191);
                $table->text('url');
                $table->text('event_types');
                $table->text('secret')->nullable();
                $table->text('headers');
                $table->boolean('active');
                $table->integer('max_attempts')->nullable();
                $table->bigInteger('created_at');
                $table->bigInteger('updated_at');
                $table->primary(['id'], 'firefly_callback_subscriptions_pk');
            });
        }

        if (! $schema->hasTable(self::EXECUTIONS)) {
            $schema->create(self::EXECUTIONS, static function (Blueprint $table) use ($connection): void {
                self::binary($table->string('id', 64), $connection);
                self::binary($table->string('subscription_id', 36), $connection);
                $table->string('event_id', 64);
                $table->string('event_type', 191);
                $table->text('url');
                $table->longText('body');
                $table->string('status', 16);
                $table->integer('attempts');
                $table->integer('response_status')->nullable();
                $table->text('last_error')->nullable();
                $table->bigInteger('created_at');
                $table->bigInteger('updated_at');
                $table->primary(['id'], 'firefly_callback_executions_pk');
                $table->index(['subscription_id'], 'firefly_callback_executions_subscription');
                $table->index(['status', 'updated_at'], 'firefly_callback_executions_status');
            });
        }
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::EXECUTIONS);
        $schema->dropIfExists(self::SUBSCRIPTIONS);
    }

    private static function binary(ColumnDefinition $column, Connection $connection): void
    {
        if ($connection instanceof MySqlConnection) {
            $modern = version_compare($connection->getServerVersion(), $connection->isMaria() ? '10.2.2' : '8.0.1', '>=');
            $column->charset('utf8mb4')->collation($modern ? ($connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin') : 'utf8mb4_bin');
        } elseif ($connection instanceof SqlServerConnection) {
            $column->collation('Latin1_General_100_BIN2');
        }
    }
}
