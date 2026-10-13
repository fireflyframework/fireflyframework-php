<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Support;

use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

/**
 * Real database connections for the event-sourcing tests, one per lane of the CI matrix (IntegrationDsn::lanes():
 * the four servers plus a sqlite FILE). A lane whose DSN variable or PDO driver is missing skips with
 * IntegrationDsn::skipReason(). fresh() drops and recreates the four eventsourcing tables only — never a database.
 */
final class EventSourcingDatabase
{
    /** @return list<string> */
    public static function lanes(): array
    {
        return IntegrationDsn::lanes();
    }

    /**
     * The lanes that are a separate server process with row-level locking: what an interleaving of two sessions
     * inside one open transaction needs (sqlite has one writer per file, so there it is only a wait).
     *
     * @return list<string>
     */
    public static function serverLanes(): array
    {
        return array_values(array_filter(IntegrationDsn::lanes(), static fn (string $lane): bool => $lane !== 'sqlite'));
    }

    public static function skipReason(string $lane): ?string
    {
        return IntegrationDsn::skipReason($lane);
    }

    public static function connection(string $lane): Connection
    {
        return IntegrationDsn::connect($lane, 'eventsourcing-'.$lane.'-'.bin2hex(random_bytes(3)), self::overrides($lane));
    }

    /** @return array<string, mixed> */
    public static function config(string $lane): array
    {
        return IntegrationDsn::connectionConfig($lane, self::overrides($lane));
    }

    public static function sqlitePath(): string
    {
        return IntegrationDsn::sqlitePath('eventsourcing');
    }

    public static function sqlite(string $database = ':memory:'): Connection
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true], 'eventsourcing-sqlite');

        return $capsule->getConnection('eventsourcing-sqlite');
    }

    public static function fresh(Connection $connection): Connection
    {
        EventSourcingSchema::drop($connection->getSchemaBuilder());
        EventSourcingSchema::create($connection->getSchemaBuilder());

        return $connection;
    }

    /** @return array<string, mixed> */
    private static function overrides(string $lane): array
    {
        return $lane === 'sqlite' ? ['database' => self::sqlitePath()] : [];
    }
}
