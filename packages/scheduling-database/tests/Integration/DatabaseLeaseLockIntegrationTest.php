<?php

declare(strict_types=1);

use Firefly\Scheduling\Database\DatabaseLeaseLock;
use Firefly\Scheduling\Database\LockSchema;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

uses(FireflyDatabaseTestCase::class);

/**
 * Two "nodes" — two connections, two DatabaseLeaseLock instances — on every server: mutual exclusion, an ended lease
 * handed over (clocks injected, no sleeping), a late holder unable to release the new holder's lease, and a held lock
 * leaving the caller's transaction usable (the savepoint — on PostgreSQL a failed INSERT would otherwise abort it).
 */
function leaseIntegrationLock(string $connection, string $owner, string $now): DatabaseLeaseLock
{
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    return new DatabaseLeaseLock($connections, $connection, $owner, static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')));
}

/** Runs one direction of the package's anonymous lease-table migration, on the default connection. */
function leaseIntegrationMigrate(string $direction): void
{
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_12_000100_create_firefly_scheduling_locks_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up') || ! method_exists($migration, 'down')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $direction === 'down' ? $migration->down() : $migration->up();
}

foreach (IntegrationDsn::lanes() as $lane) {
    it("excludes, expires and hands over leases on {$lane}", function () use ($lane): void {
        $overrides = $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('lease-lock')] : [];
        config([
            'database.connections.node_a' => IntegrationDsn::connectionConfig($lane, $overrides),
            'database.connections.node_b' => IntegrationDsn::connectionConfig($lane, $overrides),
        ]);
        DB::setDefaultConnection('node_a');
        leaseIntegrationMigrate('down');
        leaseIntegrationMigrate('up');

        try {
            $a = leaseIntegrationLock('node_a', 'node-a', '2026-10-12 10:00:00');
            expect($a->tryAcquire('reconcile', 2))->toBeTrue()
                ->and(leaseIntegrationLock('node_b', 'node-b', '2026-10-12 10:00:01.999')->tryAcquire('reconcile', 30))->toBeFalse();

            DB::connection('node_b')->transaction(function (): void {
                expect(leaseIntegrationLock('node_b', 'node-b', '2026-10-12 10:00:01')->tryAcquire('reconcile', 30))->toBeFalse()
                    ->and(DB::connection('node_b')->selectOne('select 1 as one'))->not->toBeNull();
            });

            $b = leaseIntegrationLock('node_b', 'node-b', '2026-10-12 10:00:02');
            expect($b->tryAcquire('reconcile', 30))->toBeTrue();
            $a->release('reconcile');
            expect(DB::connection('node_b')->table(LockSchema::TABLE)->where('name', 'reconcile')->value('locked_by'))->toStartWith('node-b/');

            $b->release('reconcile');
            expect(DB::connection('node_a')->table(LockSchema::TABLE)->count())->toBe(0)
                ->and(leaseIntegrationLock('node_a', 'node-a', '2026-10-12 10:00:03')->tryAcquire('reconcile', 30))->toBeTrue();
        } finally {
            leaseIntegrationMigrate('down');
            DB::connection('node_a')->disconnect();
            DB::connection('node_b')->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
