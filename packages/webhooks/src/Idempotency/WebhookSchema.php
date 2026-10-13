<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Idempotency;

use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\SqlServerConnection;

/**
 * firefly_webhook_receipts — one row per claimed delivery, primary key (provider, idempotency_key).
 *
 * Portable by construction: the key columns get a BINARY collation where the server's default is case-insensitive
 * (MySQL 8.0.1+ utf8mb4_0900_bin, MariaDB 10.2.2+ utf8mb4_nopad_bin, older utf8mb4_bin; SQL Server
 * Latin1_General_100_BIN2 — the FeatureFlagSchema precedent), because two delivery ids that differ only in case are
 * two deliveries and must never be answered "duplicate". Times are unix-second integers, so expiry comparisons mean
 * the same on every driver and in every session time zone. 64 + 191 characters keeps the key inside every engine's
 * index limit. create() is idempotent, so the migration and a test may both run it.
 */
final class WebhookSchema
{
    public const string TABLE = 'firefly_webhook_receipts';

    public static function create(Builder $schema): void
    {
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $connection = $schema->getConnection();
        $schema->create(self::TABLE, static function (Blueprint $table) use ($connection): void {
            self::binary($table->string('provider', 64), $connection);
            self::binary($table->string('idempotency_key', 191), $connection);
            $table->string('event_id', 36);
            $table->bigInteger('claimed_at');
            $table->bigInteger('expires_at')->index('firefly_webhook_receipts_expires');
            $table->primary(['provider', 'idempotency_key'], 'firefly_webhook_receipts_pk');
        });
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::TABLE);
    }

    public static function binary(ColumnDefinition $column, Connection $connection): void
    {
        if ($connection instanceof MySqlConnection) {
            $modern = version_compare($connection->getServerVersion(), $connection->isMaria() ? '10.2.2' : '8.0.1', '>=');
            $column->charset('utf8mb4')->collation($modern ? ($connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin') : 'utf8mb4_bin');
        } elseif ($connection instanceof SqlServerConnection) {
            $column->collation('Latin1_General_100_BIN2');
        }
    }
}
