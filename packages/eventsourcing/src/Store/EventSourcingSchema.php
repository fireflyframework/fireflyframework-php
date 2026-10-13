<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Store;

use DateTimeImmutable;
use DateTimeZone;
use Firefly\Data\Capability\DatabaseCapabilities;
use Firefly\EventSourcing\Exception\EventSourcingException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\SqlServerConnection;

/**
 * The four portable eventsourcing tables, created by the migration and by tests. create() is idempotent (two
 * applications sharing a database migrate whichever comes first) and inserts the `global` head row the numbering
 * locks. Every instant is a UTC `Y-m-d H:i:s.u` string, so SQLite's lexical comparison and the native datetime
 * comparison of the four servers agree.
 *
 * Ids, types, tenants and names use a binary collation on MySQL/MariaDB (utf8mb4_0900_bin / utf8mb4_nopad_bin) and SQL Server
 * (Latin1_General_100_BIN2): `Order-1` and `order-1` are two streams, and `Acme` and `acme` two tenants, as they are
 * in memory (the OrchestrationSchema precedent): a tenant filter that folded case would hand one tenant another's events. `global_position` has a plain index, not a unique one: SQL Server's unique index admits one NULL, and
 * every unnumbered event is NULL. Uniqueness comes from the head-row lock the numbering holds. SQLite does not
 * enforce widths, so the adapters call fit() before they write, and an oversized value is refused with the same
 * exception on every driver.
 */
final class EventSourcingSchema
{
    public const string EVENTS = 'firefly_eventsourcing_events';

    public const string HEAD = 'firefly_eventsourcing_head';

    public const string SNAPSHOTS = 'firefly_eventsourcing_snapshots';

    public const string CHECKPOINTS = 'firefly_eventsourcing_checkpoints';

    public const string GLOBAL_STREAM = 'global';

    public const int ID_LENGTH = 128;

    public const int EVENT_ID_LENGTH = 64;

    public const int TYPE_LENGTH = 191;

    public const int TENANT_LENGTH = 64;

    public const int CORRELATION_LENGTH = 191;

    public const int NAME_LENGTH = 191;

    private const string SQL_SERVER_BINARY = 'Latin1_General_100_BIN2';

    public static function fit(string $field, ?string $value, int $length): void
    {
        if ($value !== null && mb_strlen($value, 'UTF-8') > $length) {
            throw new EventSourcingException("{$field} is ".mb_strlen($value, 'UTF-8')." characters long; the eventsourcing tables hold at most {$length}.", 'EVENT_STORE_VALUE_TOO_LONG', 422);
        }
    }

    public static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function parseInstant(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * `WITH (READPAST)` on SQL Server, where a plain read waits on another session's uncommitted row; nothing elsewhere.
     * Only a Laravel Connection names its driver, so anything else reads plainly.
     */
    public static function readPast(QueryBuilder $query, ConnectionInterface $connection): QueryBuilder
    {
        $readPast = $connection instanceof Connection && DatabaseCapabilities::fromVersion($connection->getDriverName(), '')->supportsReadPast();

        return $readPast ? $query->lock('with(readpast)') : $query;
    }

    public static function create(Builder $schema): void
    {
        $collation = self::binaryCollation($schema);
        $binary = static function (ColumnDefinition $column) use ($collation): ColumnDefinition {
            if ($collation === self::SQL_SERVER_BINARY) {
                $column->collation($collation);
            } elseif ($collation !== null) {
                $column->charset('utf8mb4')->collation($collation);
            }

            return $column;
        };

        if (! $schema->hasTable(self::EVENTS)) {
            $schema->create(self::EVENTS, static function (Blueprint $table) use ($binary): void {
                $table->bigIncrements('id');
                $binary($table->string('event_id', self::EVENT_ID_LENGTH))->unique('firefly_eventsourcing_events_event_id');
                $binary($table->string('aggregate_id', self::ID_LENGTH));
                $binary($table->string('aggregate_type', self::TYPE_LENGTH));
                $table->bigInteger('aggregate_version');
                $table->bigInteger('global_position')->nullable();
                $binary($table->string('event_type', self::TYPE_LENGTH));
                $table->unsignedInteger('event_version')->default(1);
                $binary($table->string('tenant_id', self::TENANT_LENGTH))->nullable();
                $table->string('correlation_id', self::CORRELATION_LENGTH)->nullable();
                $table->longText('payload');
                $table->dateTime('occurred_at', 6);
                $table->dateTime('recorded_at', 6);
                $table->unique(['aggregate_id', 'aggregate_version'], 'firefly_eventsourcing_events_stream');
                $table->index(['global_position'], 'firefly_eventsourcing_events_position');
                $table->index(['event_type', 'global_position'], 'firefly_eventsourcing_events_type');
                $table->index(['aggregate_type', 'global_position'], 'firefly_eventsourcing_events_aggregate_type');
                $table->index(['tenant_id', 'global_position'], 'firefly_eventsourcing_events_tenant');
                $table->index(['occurred_at'], 'firefly_eventsourcing_events_occurred');
            });
        }

        if (! $schema->hasTable(self::HEAD)) {
            $schema->create(self::HEAD, static function (Blueprint $table) use ($binary): void {
                $binary($table->string('name', 64))->primary();
                $table->bigInteger('position')->default(0);
                $table->dateTime('updated_at', 6);
            });
        }
        $connection = $schema->getConnection();
        if (! $connection->table(self::HEAD)->where('name', self::GLOBAL_STREAM)->exists()) {
            $connection->table(self::HEAD)->insert(['name' => self::GLOBAL_STREAM, 'position' => 0, 'updated_at' => self::instant(new DateTimeImmutable)]);
        }

        if (! $schema->hasTable(self::SNAPSHOTS)) {
            $schema->create(self::SNAPSHOTS, static function (Blueprint $table) use ($binary): void {
                $binary($table->string('aggregate_id', self::ID_LENGTH));
                $table->bigInteger('aggregate_version');
                $binary($table->string('aggregate_type', self::TYPE_LENGTH));
                $table->unsignedInteger('state_version')->default(1);
                $table->longText('state');
                $table->dateTime('created_at', 6);
                $table->primary(['aggregate_id', 'aggregate_version'], 'firefly_eventsourcing_snapshots_pk');
            });
        }

        if (! $schema->hasTable(self::CHECKPOINTS)) {
            $schema->create(self::CHECKPOINTS, static function (Blueprint $table) use ($binary): void {
                $binary($table->string('projection', self::NAME_LENGTH))->primary();
                $table->bigInteger('position')->default(0);
                $table->dateTime('updated_at', 6);
                $table->text('last_error')->nullable();
                $table->dateTime('failed_at', 6)->nullable();
            });
        }
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::CHECKPOINTS);
        $schema->dropIfExists(self::SNAPSHOTS);
        $schema->dropIfExists(self::HEAD);
        $schema->dropIfExists(self::EVENTS);
    }

    private static function binaryCollation(Builder $schema): ?string
    {
        $connection = $schema->getConnection();
        if ($connection instanceof SqlServerConnection) {
            return self::SQL_SERVER_BINARY;
        }
        if (! $connection instanceof MySqlConnection) {
            return null;
        }

        $modern = version_compare($connection->getServerVersion(), $connection->isMaria() ? '10.2.2' : '8.0.1', '>=');

        return $modern ? ($connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin') : 'utf8mb4_bin';
    }
}
