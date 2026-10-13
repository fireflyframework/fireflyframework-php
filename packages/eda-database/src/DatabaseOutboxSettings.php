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
 * database key wins, and a key present but null (an unset env()) counts as unset for the connection. Values a server cannot honour are floored rather than rejected: a zero lease or zero attempts
 * would make the outbox drop or spin, and the floor is what "as small as possible" means.
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
        $connection = match (true) {
            $config->has('firefly.eda.database.connection') => $config->string('firefly.eda.database.connection', ''),
            $config->has('firefly.eda.postgres.connection') => $config->string('firefly.eda.postgres.connection', ''),
            default => null,
        };

        $channel = $config->has('firefly.eda.database.channel')
            ? $config->string('firefly.eda.database.channel')
            : $config->string('firefly.eda.postgres.channel', 'firefly_eda_events');

        $maxAttempts = $config->has('firefly.eda.database.max-attempts')
            ? $config->int('firefly.eda.database.max-attempts')
            : $config->int('firefly.eda.postgres.max_attempts', 3);

        return new self(
            connection: $connection === '' ? null : $connection,
            channel: $channel,
            maxAttempts: max(1, $maxAttempts),
            claimStrategy: $config->string('firefly.eda.database.claim-strategy', 'auto'),
            leaseSeconds: (float) max(1, $config->int('firefly.eda.database.lease-seconds', 300)),
            pollIntervalMs: max(10, $config->int('firefly.eda.database.poll-interval-ms', 500)),
        );
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
