<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * A Laravel connection from one of the program's DSN variables (`host=…;port=…;dbname=…;user=…;password=…`). The
 * feature-flags DatabaseStoreIntegration shape, plus sqlsrv; it moves to W1's shared packages/testing helper when
 * W1 merges (spec Decision 15).
 */
final class WebhookDatabases
{
    public static function connection(string $driver, string $variable): Connection
    {
        $dsn = getenv($variable);
        if ($dsn === false || $dsn === '') {
            throw new RuntimeException("Set {$variable} to an isolated database.");
        }

        $parts = [];
        foreach (explode(';', $dsn) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }

        $capsule = new Manager;
        $capsule->addConnection([
            'driver' => $driver,
            'host' => $parts['host'] ?? '127.0.0.1',
            'port' => (int) ($parts['port'] ?? 0),
            'database' => $parts['dbname'] ?? '',
            'username' => $parts['user'] ?? '',
            'password' => $parts['password'] ?? '',
            'charset' => match ($driver) {
                'pgsql', 'sqlsrv' => 'utf8',
                default => 'utf8mb4',
            },
            'prefix' => '',
            'schema' => 'public',
            'trust_server_certificate' => true,
        ]);

        return $capsule->getConnection();
    }
}
