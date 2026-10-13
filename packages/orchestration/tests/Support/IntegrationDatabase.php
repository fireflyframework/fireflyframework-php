<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

/**
 * Real database connections for the orchestration integration tests, one per lane of the CI matrix
 * (IntegrationDsn::lanes(): the four servers plus a sqlite FILE). Every DSN is read by firefly/testing's
 * IntegrationDsn, so a lane whose variable or PDO driver is missing skips with IntegrationDsn::skipReason(), and a
 * CI job that exports only its own DSN runs exactly its own lane (and sqlite). fresh() drops and recreates the three
 * orchestration tables only — never a database.
 */
final class IntegrationDatabase
{
    /** @return list<string> every lane of the integration matrix */
    public static function lanes(): array
    {
        return IntegrationDsn::lanes();
    }

    /**
     * The lanes that are a separate server process with row-level locking — what a test of two concurrent sessions
     * interleaving inside one open transaction needs. sqlite has one writer per database file, so a second session's
     * write while the first holds its transaction is not an interleaving there, only a wait.
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

    /** The Laravel driver name a connection on $lane reports. */
    public static function driver(string $lane): string
    {
        return IntegrationDsn::DATABASES[$lane]['driver'];
    }

    public static function connection(string $lane): Connection
    {
        return IntegrationDsn::connect($lane, 'orchestration-'.$lane, self::overrides($lane));
    }

    /**
     * The Laravel connection config for one lane — what connection() opens, and what a booted capstone sets under
     * `database.connections.*` to run the real pipeline against that server.
     *
     * @return array<string, mixed>
     */
    public static function config(string $lane): array
    {
        return IntegrationDsn::connectionConfig($lane, self::overrides($lane));
    }

    /**
     * What CrashScenarios::crash() hands the child process so it opens the same database: the sqlite lane's file as
     * `sqlite:<path>` (the form the unit-level crash test uses too), a server lane as `lane:<lane>` — the child
     * inherits the environment, so it reads the same DSN.
     */
    public static function crashTarget(string $lane): string
    {
        return $lane === 'sqlite' ? 'sqlite:'.IntegrationDsn::sqlitePath('orchestration') : 'lane:'.$lane;
    }

    /** An in-process sqlite database for the unit tests (`:memory:` unless a file is named). */
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

    /**
     * The sqlite lane's one file per process, shared by every connection a test opens — and by the crash process
     * CrashScenarios spawns, which is handed the same path.
     *
     * @return array<string, mixed>
     */
    private static function overrides(string $lane): array
    {
        return $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('orchestration')] : [];
    }
}
