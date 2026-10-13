<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Illuminate\Database\Connection;

/**
 * A configured strategy name, resolved against the server at the FIRST claim and then reused for that connection.
 * Beans may be built at boot, and probing the server version there would open a database connection on every web
 * request of an application that only publishes; a consumer or relay resolves when it first claims, which is when
 * it needs the connection anyway. An unsupported forced name therefore fails at the first claim — before any row is
 * taken — rather than at boot.
 */
final class ConfiguredClaimStrategy implements OutboxClaimStrategy
{
    /** @var array<string, OutboxClaimStrategy> connection name => resolved strategy */
    private array $resolved = [];

    private ?OutboxClaimStrategy $last = null;

    public function __construct(private readonly string $configured = OutboxClaimStrategies::AUTO) {}

    public function name(): string
    {
        return $this->last?->name() ?? $this->configured;
    }

    public function claim(Connection $connection, OutboxClaim $claim): array
    {
        return $this->strategyFor($connection)->claim($connection, $claim);
    }

    public function strategyFor(Connection $connection): OutboxClaimStrategy
    {
        $key = (string) $connection->getName();

        return $this->last = $this->resolved[$key] ??= OutboxClaimStrategies::resolve($connection, $this->configured);
    }
}
