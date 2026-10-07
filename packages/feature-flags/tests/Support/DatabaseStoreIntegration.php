<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;
use RuntimeException;

final class DatabaseStoreIntegration
{
    public static function connection(string $driver, string $variable): Connection
    {
        $dsn = getenv($variable);
        if ($dsn === false || $dsn === '') {
            throw new RuntimeException('An isolated integration DSN is required.');
        }
        $parts = [];
        foreach (explode(';', $dsn) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $parts[$key] = $value;
        }
        $capsule = new Manager;
        $capsule->addConnection([
            'driver' => $driver, 'host' => $parts['host'], 'port' => (int) $parts['port'],
            'database' => $parts['dbname'], 'username' => $parts['user'], 'password' => $parts['password'],
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4', 'prefix' => '', 'schema' => 'public',
        ]);

        return $capsule->getConnection();
    }
}
