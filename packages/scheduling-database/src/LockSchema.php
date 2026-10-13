<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * firefly_scheduling_locks: one row per lock NAME — who holds it and until when. A 255-character primary key fits
 * InnoDB's 3072-byte index limit under utf8mb4 and SQL Server's 900-byte key as nvarchar (510 bytes).
 */
final class LockSchema
{
    public const string TABLE = 'firefly_scheduling_locks';

    public static function blueprint(Blueprint $table): void
    {
        $table->string('name')->primary();
        $table->string('locked_by');
        $table->dateTime('locked_until', 3);
        $table->dateTime('locked_at', 3);
    }
}
