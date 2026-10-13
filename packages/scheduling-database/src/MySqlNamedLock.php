<?php

declare(strict_types=1);

namespace Firefly\Scheduling\Database;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Scheduling\Lock\DistributedLock;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * MySQL and MariaDB named locks — `GET_LOCK(name, 0)` / `RELEASE_LOCK(name)` — behind
 * firefly.scheduling.lock.provider=mysql. The MySQL-family counterpart of PgAdvisoryLock: the lock belongs to the
 * database SESSION, is released when the connection closes (a crashed node frees its locks), needs no table, and has
 * NO TTL — $ttlSeconds is accepted for the port's sake and ignored (use provider=database for a TTL honoured on every
 * driver). A zero timeout keeps tryAcquire() non-blocking, as the port requires. Lock names are limited to 64
 * characters by the server; a longer name is hashed to `firefly:<sha1>` (48 characters), deterministic across nodes.
 * GET_LOCK answers 1 (acquired), 0 (held elsewhere) or NULL (an error): only 1 is an acquisition.
 */
final class MySqlNamedLock implements DistributedLock
{
    public const int MAX_NAME_LENGTH = 64;

    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
    ) {}

    public static function key(string $name): string
    {
        return strlen($name) <= self::MAX_NAME_LENGTH ? $name : 'firefly:'.sha1($name);
    }

    public function tryAcquire(string $name, float $ttlSeconds): bool
    {
        $row = $this->db()->selectOne('select get_lock(?, 0) as locked', [self::key($name)]);
        $locked = is_object($row) ? (get_object_vars($row)['locked'] ?? null) : null;

        return is_numeric($locked) && (int) $locked === 1;
    }

    public function release(string $name): void
    {
        $this->db()->selectOne('select release_lock(?) as released', [self::key($name)]);
    }

    private function db(): Connection
    {
        $connection = $this->connections->connection($this->connection);

        if (! $connection instanceof Connection || ! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            throw new ConfigurationException(sprintf(
                'firefly.scheduling.lock.provider=mysql needs a mysql or mariadb connection; "%s" is %s. Set '
                .'firefly.scheduling.lock.mysql.connection, or use provider=database, which works on every driver.',
                $this->connection ?? 'the default connection',
                $connection instanceof Connection ? $connection->getDriverName() : get_debug_type($connection),
            ));
        }

        return $connection;
    }
}
