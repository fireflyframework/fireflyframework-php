<?php

declare(strict_types=1);

namespace Firefly\Data\Capability;

use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * What the server behind a connection can do, read once from the driver name and the version PDO reports — the
 * capability probe PROGRAM.md rule 3 asks for. Code that needs locking SQL asks THIS, never `getDriverName()`:
 *
 *   - `SKIP LOCKED` exists on PostgreSQL (9.5+, older than anything Laravel 13 connects to), MySQL from 8.0.1 and
 *     MariaDB from 10.6. A `mysql`-configured connection whose server is MariaDB — the way MariaDB was configured
 *     before Laravel had a `mariadb` driver — is recognised from the version string ("…-MariaDB…"), with the
 *     "5.5.5-" prefix older MariaDB servers send for replication compatibility stripped first.
 *   - `READPAST` is a SQL Server table hint and exists nowhere else.
 *
 * The probe never throws: a version it cannot read is "", which answers "no" for every version-gated feature, so a
 * caller falls back to its portable path instead of failing — the safe direction.
 */
final readonly class DatabaseCapabilities
{
    public function __construct(
        public string $driver,
        public string $serverVersion,
        public bool $mariaDb,
    ) {}

    /**
     * The configured driver name wins (PDO calls a `mariadb` connection "mysql"). A connection built without a config
     * array has no configured driver (`getDriverName()` then returns null despite its `string` docblock), so the probe
     * falls back to the name PDO itself reports.
     */
    public static function of(Connection $connection): self
    {
        $configured = $connection->getConfig('driver');
        $driver = is_string($configured) ? $configured : '';

        try {
            $pdo = $connection->getPdo();
            $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

            if ($driver === '') {
                $reported = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
                $driver = is_string($reported) ? $reported : '';
            }
        } catch (Throwable) {
            $version = '';
        }

        return self::fromVersion($driver, is_string($version) ? $version : '');
    }

    public static function fromVersion(string $driver, string $serverVersion): self
    {
        return new self($driver, $serverVersion, $driver === 'mariadb' || stripos($serverVersion, 'mariadb') !== false);
    }

    public function version(): string
    {
        $raw = $this->serverVersion;

        if ($this->mariaDb && str_starts_with($raw, '5.5.5-')) {
            $raw = substr($raw, strlen('5.5.5-'));
        }

        return preg_match('/^\d+(?:\.\d+){0,2}/', $raw, $matches) === 1 ? $matches[0] : '0';
    }

    public function isMySqlFamily(): bool
    {
        return $this->driver === 'mysql' || $this->driver === 'mariadb';
    }

    public function supportsSkipLocked(): bool
    {
        return match (true) {
            $this->driver === 'pgsql' => true,
            $this->isMySqlFamily() && $this->mariaDb => version_compare($this->version(), '10.6', '>='),
            $this->isMySqlFamily() => version_compare($this->version(), '8.0.1', '>='),
            default => false,
        };
    }

    public function supportsReadPast(): bool
    {
        return $this->driver === 'sqlsrv';
    }

    public function describe(): string
    {
        return $this->driver.' '.($this->serverVersion === '' ? '(unknown version)' : $this->serverVersion);
    }
}
