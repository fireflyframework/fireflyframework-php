<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Firefly\Orchestration\Core\Exception\OrchestrationException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Database\SqlServerConnection;

/**
 * The three portable orchestration tables (spec §6), created by the migration and by tests. Every instant is
 * written as a UTC `Y-m-d H:i:s.u` string, so SQLite's lexical comparison and the native datetime comparison
 * of PostgreSQL, MySQL, MariaDB and SQL Server agree. String lengths keep every index under InnoDB's
 * 3072-byte and SQL Server's 1700-byte key limits with utf8mb4 / nvarchar. On MySQL and MariaDB the id,
 * name and search-attribute columns use a binary collation, so `customer = 'Ada'` never matches `'ada'` —
 * the comparison the memory and cache providers make (the FeatureFlagSchema precedent); SQL Server, whose
 * default collation is case-insensitive too, gets `Latin1_General_100_BIN2` on the same columns. create() is
 * idempotent: two applications sharing a database migrate whichever comes first.
 *
 * The length constants are the columns' widths in characters. SQLite ignores a varchar's width and
 * PostgreSQL, MySQL, MariaDB and SQL Server refuse (or, outside strict mode, truncate) a longer value, so
 * the database adapters call fit() before they write: an oversized id, name or search attribute is refused
 * with the same OrchestrationException on every driver instead of a driver-specific QueryException, or a
 * silently shortened key, on some of them.
 */
final class OrchestrationSchema
{
    public const string EXECUTIONS = 'firefly_orchestration_executions';

    public const string SEARCH_ATTRIBUTES = 'firefly_orchestration_search_attributes';

    public const string DEAD_LETTERS = 'firefly_orchestration_dead_letters';

    public const int ID_LENGTH = 64;

    public const int NAME_LENGTH = 191;

    public const int ATTRIBUTE_KEY_LENGTH = 128;

    public const int ATTRIBUTE_VALUE_LENGTH = 191;

    public const int TYPE_LENGTH = 255;

    /**
     * Refuses a value its column cannot hold, naming the field it came from. Widths count characters, as
     * varchar on PostgreSQL and utf8mb4 MySQL/MariaDB do; SQL Server's nvarchar counts UTF-16 code units, so
     * there a value made of characters outside the Basic Multilingual Plane (emoji) holds half as many.
     */
    public static function fit(string $field, ?string $value, int $length): void
    {
        if ($value !== null && mb_strlen($value, 'UTF-8') > $length) {
            throw new OrchestrationException("{$field} is ".mb_strlen($value, 'UTF-8')." characters long; the orchestration database tables hold at most {$length}.", 'ORCHESTRATION_UNSERIALIZABLE');
        }
    }

    public static function instant(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }

    public static function create(Builder $schema): void
    {
        $collation = self::binaryCollation($schema);
        $binary = static function (ColumnDefinition $column) use ($collation): void {
            if ($collation === self::SQL_SERVER_BINARY) {
                $column->collation($collation);
            } elseif ($collation !== null) {
                $column->charset('utf8mb4')->collation($collation);
            }
        };

        if (! $schema->hasTable(self::EXECUTIONS)) {
            $schema->create(self::EXECUTIONS, static function (Blueprint $table) use ($binary): void {
                $binary($table->string('correlation_id', self::ID_LENGTH)->primary());
                $binary($table->string('execution_name', self::NAME_LENGTH));
                $table->string('pattern', 16);
                $table->string('status', 24);
                $binary($table->string('parent_correlation_id', self::ID_LENGTH)->nullable());
                $table->dateTime('started_at', 6);
                $table->dateTime('updated_at', 6);
                $table->dateTime('completed_at', 6)->nullable();
                $table->dateTime('wake_at', 6)->nullable();
                $table->longText('payload');
                $table->index(['status', 'updated_at'], 'firefly_orchestration_executions_status_updated');
                $table->index(['pattern', 'status'], 'firefly_orchestration_executions_pattern_status');
                $table->index(['wake_at'], 'firefly_orchestration_executions_wake');
                $table->index(['execution_name'], 'firefly_orchestration_executions_name');
            });
        }

        if (! $schema->hasTable(self::SEARCH_ATTRIBUTES)) {
            $schema->create(self::SEARCH_ATTRIBUTES, static function (Blueprint $table) use ($binary): void {
                $binary($table->string('correlation_id', self::ID_LENGTH));
                $binary($table->string('attribute_key', self::ATTRIBUTE_KEY_LENGTH));
                $binary($table->string('attribute_value', self::ATTRIBUTE_VALUE_LENGTH));
                $table->primary(['correlation_id', 'attribute_key'], 'firefly_orchestration_search_attributes_pk');
                $table->index(['attribute_key', 'attribute_value'], 'firefly_orchestration_search_attributes_lookup');
            });
        }

        if (! $schema->hasTable(self::DEAD_LETTERS)) {
            $schema->create(self::DEAD_LETTERS, static function (Blueprint $table) use ($binary): void {
                $binary($table->string('id', self::ID_LENGTH)->primary());
                $binary($table->string('execution_name', self::NAME_LENGTH));
                $binary($table->string('correlation_id', self::ID_LENGTH));
                $table->string('pattern', 16);
                $table->string('step_id', self::NAME_LENGTH)->nullable();
                $table->string('status_at_failure', 24);
                $table->longText('error_message');
                $table->string('error_type', self::TYPE_LENGTH);
                $table->longText('input');
                $table->longText('headers');
                $table->unsignedInteger('retry_count')->default(0);
                $table->dateTime('created_at', 6);
                $table->dateTime('last_retried_at', 6)->nullable();
                $table->index(['execution_name'], 'firefly_orchestration_dead_letters_name');
                $table->index(['correlation_id'], 'firefly_orchestration_dead_letters_correlation');
                $table->index(['created_at'], 'firefly_orchestration_dead_letters_created');
            });
        }
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::DEAD_LETTERS);
        $schema->dropIfExists(self::SEARCH_ATTRIBUTES);
        $schema->dropIfExists(self::EXECUTIONS);
    }

    private const string SQL_SERVER_BINARY = 'Latin1_General_100_BIN2';

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
