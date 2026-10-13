<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox\Claim;

use Firefly\Data\Capability\DatabaseCapabilities;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Database\Connection;

/**
 * firefly.eda.database.claim-strategy → a strategy. `auto` asks the capability probe (PROGRAM.md rule 3: capability,
 * not driver name); a forced name is honoured only when the server really supports it — a forced `skip-locked` on
 * MySQL 5.7 is a ConfigurationException naming the server, never a silent downgrade to something unsafe or slow.
 */
final class OutboxClaimStrategies
{
    public const string AUTO = 'auto';

    /** @var list<string> */
    public const array NAMES = [self::AUTO, SkipLockedClaim::NAME, ReadPastClaim::NAME, OptimisticLeaseClaim::NAME];

    /** The strategy for a configured name, resolved at the first claim (no connection is opened here). */
    public static function configured(string $name): OutboxClaimStrategy
    {
        return $name === OptimisticLeaseClaim::NAME ? new OptimisticLeaseClaim : new ConfiguredClaimStrategy($name);
    }

    public static function resolve(Connection $connection, string $name = self::AUTO): OutboxClaimStrategy
    {
        return self::forCapabilities(DatabaseCapabilities::of($connection), $name);
    }

    public static function forCapabilities(DatabaseCapabilities $capabilities, string $name = self::AUTO): OutboxClaimStrategy
    {
        return match ($name) {
            self::AUTO => match (true) {
                $capabilities->supportsSkipLocked() => new SkipLockedClaim,
                $capabilities->supportsReadPast() => new ReadPastClaim,
                default => new OptimisticLeaseClaim,
            },
            SkipLockedClaim::NAME => $capabilities->supportsSkipLocked() ? new SkipLockedClaim : throw self::unsupported($name, $capabilities),
            ReadPastClaim::NAME => $capabilities->supportsReadPast() ? new ReadPastClaim : throw self::unsupported($name, $capabilities),
            OptimisticLeaseClaim::NAME => new OptimisticLeaseClaim,
            default => throw new ConfigurationException(sprintf(
                'firefly.eda.database.claim-strategy="%s" is not one of [%s].',
                $name,
                implode('|', self::NAMES),
            )),
        };
    }

    private static function unsupported(string $name, DatabaseCapabilities $capabilities): ConfigurationException
    {
        return new ConfigurationException(sprintf(
            'firefly.eda.database.claim-strategy="%s" but %s does not support it (SKIP LOCKED needs PostgreSQL, MySQL '
            .'8.0.1+ or MariaDB 10.6+; READPAST needs SQL Server). Use "auto" or "optimistic-lease".',
            $name,
            $capabilities->describe(),
        ));
    }
}
