<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Postgres;

use Firefly\Scheduling\Lock\DistributedLock;
use Illuminate\Support\Facades\DB;

/**
 * A ShedLock-style distributed lock backed by Postgres SESSION-LEVEL advisory locks
 * (pg_try_advisory_lock / pg_advisory_unlock). The lock is held for the life of the DB session and
 * auto-releases if the connection dies, so there is NO TTL — the $ttlSeconds argument is accepted for
 * interface parity and deliberately ignored (documented divergence from CacheLock in scheduling.md).
 */
final class PgAdvisoryLock implements DistributedLock
{
    public function __construct(private readonly ?string $connection = null) {}

    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        $row = DB::connection($this->connection)
            ->selectOne('SELECT pg_try_advisory_lock(?) AS locked', [self::key($name)]);

        return (bool) data_get($row, 'locked', false);
    }

    public function release(string $name): void
    {
        DB::connection($this->connection)
            ->statement('SELECT pg_advisory_unlock(?)', [self::key($name)]);
    }

    /**
     * Deterministic name -> signed 64-bit integer (Postgres advisory-lock keys are bigint). Take the top
     * 60 bits of a sha256 digest (collision-resistant across realistic lock-name cardinality) and map the
     * unsigned value into the signed int8 range. Stays within PHP_INT range on 64-bit builds.
     */
    public static function key(string $name): int
    {
        $hex = substr(hash('sha256', $name), 0, 15); // 60 bits → always < 2^63, so no overflow
        $value = (int) hexdec($hex);

        // Fold into the full signed range deterministically (keeps distinctness of the 60-bit space).
        return $value - (1 << 59);
    }
}
