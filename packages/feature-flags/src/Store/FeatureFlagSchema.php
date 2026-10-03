<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The two tables of the shared store schema (spec §4.6) — the interop surface PyFly reads and writes too, so
 * column names, types and the change index are the contract's, not Laravel's conventions. create() is
 * idempotent: when two applications share a database, whichever migrates first creates the tables.
 */
final class FeatureFlagSchema
{
    public const string FLAGS = 'firefly_feature_flags';

    public const string CHANGES = 'firefly_feature_flag_changes';

    public const string CHANGES_INDEX = 'firefly_feature_flag_changes_key';

    public static function create(Builder $schema): void
    {
        $connection = $schema->getConnection();
        $collation = null;
        if ($connection instanceof MySqlConnection) {
            $modern = version_compare($connection->getServerVersion(), $connection->isMaria() ? '10.2.2' : '8.0.1', '>=');
            $collation = $modern ? ($connection->isMaria() ? 'utf8mb4_nopad_bin' : 'utf8mb4_0900_bin') : 'utf8mb4_bin';
        }
        if (! $schema->hasTable(self::FLAGS)) {
            $schema->create(self::FLAGS, static function (Blueprint $table) use ($collation): void {
                $key = $table->string('flag_key', 128)->primary();
                if ($collation !== null) {
                    $key->charset('utf8mb4')->collation($collation);
                }
                $table->longText('definition');
                $table->integer('version');
                if ($collation !== null) {
                    $table->dateTime('updated_at', 6);
                } else {
                    $table->timestamp('updated_at', 6);
                }
                $table->string('updated_by', 255)->nullable();
            });
        }

        if (! $schema->hasTable(self::CHANGES)) {
            $schema->create(self::CHANGES, static function (Blueprint $table) use ($collation): void {
                $table->bigIncrements('id');
                $key = $table->string('flag_key', 128);
                if ($collation !== null) {
                    $key->charset('utf8mb4')->collation($collation);
                }
                $table->string('action', 16);
                $table->longText('definition')->nullable();
                $table->longText('previous')->nullable();
                $table->string('actor', 255)->nullable();
                if ($collation !== null) {
                    $table->dateTime('changed_at', 6);
                } else {
                    $table->timestamp('changed_at', 6);
                }
                $table->index(['flag_key', 'id'], self::CHANGES_INDEX);
            });
        }
    }

    public static function drop(Builder $schema): void
    {
        $schema->dropIfExists(self::CHANGES);
        $schema->dropIfExists(self::FLAGS);
    }
}
