<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Real database connections for the integration lane, from the program's DSN variables
 * (`host=…;port=…;dbname=…;user=…;password=…`). A missing variable or PDO driver SKIPS, never fails. W1 moves
 * this parsing into packages/testing; until this branch merges it, it lives here (spec decision D16).
 * fresh() drops and recreates the three orchestration tables only — never a database.
 */
final class IntegrationDatabase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function servers(): array
    {
        return [
            'PostgreSQL' => ['pgsql', 'FIREFLY_PG_DSN', 'pdo_pgsql'],
            'MySQL' => ['mysql', 'FIREFLY_MYSQL_DSN', 'pdo_mysql'],
            'MariaDB' => ['mariadb', 'FIREFLY_MARIADB_DSN', 'pdo_mysql'],
            'SQL Server' => ['sqlsrv', 'FIREFLY_SQLSRV_DSN', 'pdo_sqlsrv'],
        ];
    }

    public static function skipReason(string $variable, string $extension): ?string
    {
        $dsn = getenv($variable);
        if (! is_string($dsn) || $dsn === '') {
            return "Set {$variable} to an isolated server.";
        }

        return extension_loaded($extension) ? null : "The {$extension} extension is not loaded.";
    }

    public static function connection(string $driver, string $variable): Connection
    {
        $dsn = getenv($variable);
        if (! is_string($dsn) || $dsn === '') {
            throw new RuntimeException("{$variable} is not set.");
        }

        $parts = [];
        foreach (explode(';', $dsn) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[trim($key)] = trim($value);
        }

        $config = [
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 0),
            'database' => $parts['dbname'] ?? '',
            'username' => $parts['user'] ?? '',
            'password' => $parts['password'] ?? '',
            'prefix' => '',
        ];
        $config += match ($driver) {
            'pgsql' => ['charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer'],
            'sqlsrv' => ['charset' => 'utf8', 'trust_server_certificate' => true],
            default => ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
        };

        $capsule = new Manager;
        $capsule->addConnection($config, $variable);

        return $capsule->getConnection($variable);
    }

    public static function sqlite(string $database = ':memory:'): Connection
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true], 'orchestration-sqlite');

        return $capsule->getConnection('orchestration-sqlite');
    }

    public static function fresh(Connection $connection): Connection
    {
        OrchestrationSchema::drop($connection->getSchemaBuilder());
        OrchestrationSchema::create($connection->getSchemaBuilder());

        return $connection;
    }
}
