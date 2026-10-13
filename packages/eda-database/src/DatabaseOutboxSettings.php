<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;

/**
 * The outbox's settings, read ONCE and shared by both outbox providers — `database` and `postgres` — so an app that
 * moves from one to the other keeps its configuration. Each firefly.eda.database.* key falls back to the
 * firefly.eda.postgres.* spelling it replaces (PROGRAM.md rule 4: existing keys keep working); when both are set the
 * database key wins. A key that is present but NULL — the usual `env('X')` with X unset — counts as UNSET for every
 * key, so it falls through to its alias and then to the default: Config::has() is true for a null value, and taking
 * the database branch there would silently drain the default connection's outbox while the postgres alias names
 * another. Values a server cannot honour are floored rather than rejected: a zero lease or zero attempts would make
 * the outbox drop or spin, and the floor is what "as small as possible" means.
 */
final readonly class DatabaseOutboxSettings
{
    public function __construct(
        public ?string $connection = null,
        public string $channel = 'firefly_eda_events',
        public int $maxAttempts = 3,
        public string $claimStrategy = 'auto',
        public float $leaseSeconds = 300.0,
        public int $pollIntervalMs = 500,
    ) {}

    public static function from(Config $config): self
    {
        $connectionKey = self::firstSetKey($config, 'firefly.eda.database.connection', 'firefly.eda.postgres.connection');
        $connection = $connectionKey === null ? null : $config->string($connectionKey);

        $channelKey = self::firstSetKey($config, 'firefly.eda.database.channel', 'firefly.eda.postgres.channel');
        $channel = $channelKey === null ? 'firefly_eda_events' : $config->string($channelKey);

        $maxAttemptsKey = self::firstSetKey($config, 'firefly.eda.database.max-attempts', 'firefly.eda.postgres.max_attempts');
        $maxAttempts = $maxAttemptsKey === null ? 3 : $config->int($maxAttemptsKey);

        return new self(
            connection: $connection === '' ? null : $connection,
            channel: $channel,
            maxAttempts: max(1, $maxAttempts),
            claimStrategy: $config->string('firefly.eda.database.claim-strategy', 'auto'),
            leaseSeconds: (float) max(1, $config->int('firefly.eda.database.lease-seconds', 300)),
            pollIntervalMs: max(10, $config->int('firefly.eda.database.poll-interval-ms', 500)),
        );
    }

    /**
     * The first of $keys whose value is set AND not null, or null when none is: the alias rule every outbox key
     * follows, shared with RelayDownstream so the relay's downstream key cannot drift from it. A null value is an
     * unset env() and must fall through to the alias, which Config::has() alone would not do.
     *
     * @internal
     */
    public static function firstSetKey(Config $config, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            if ($config->get($key) !== null) {
                return $key;
            }
        }

        return null;
    }

    /** The outbox connection as the concrete Connection the claim needs (driver name, PDO, transactions). */
    public function connectionFrom(ConnectionResolverInterface $connections): Connection
    {
        $connection = $connections->connection($this->connection);

        return $connection instanceof Connection ? $connection : throw self::notConcrete($this->connection, $connection);
    }

    private static function notConcrete(?string $name, ConnectionInterface $connection): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            'The outbox connection "%s" resolved to %s; the outbox needs an Illuminate\Database\Connection (it reads the '
            .'driver and the server version to choose its claim).',
            $name ?? 'default',
            get_debug_type($connection),
        ));
    }
}
