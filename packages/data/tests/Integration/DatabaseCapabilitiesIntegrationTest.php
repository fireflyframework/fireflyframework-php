<?php

declare(strict_types=1);

use Firefly\Data\Capability\DatabaseCapabilities;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Schema\Blueprint;

/**
 * The probe against the real servers of the matrix: every server lane reports a readable version, the three SKIP
 * LOCKED servers say so (and the SQL actually runs), SQL Server says READPAST, sqlite says neither.
 */
foreach (IntegrationDsn::lanes() as $lane) {
    it("probes the {$lane} server it is connected to", function () use ($lane): void {
        $connection = IntegrationDsn::connect($lane, 'capabilities-'.$lane);
        $capabilities = DatabaseCapabilities::of($connection);

        expect($capabilities->version())->not->toBe('0')
            ->and($capabilities->supportsSkipLocked())->toBe(in_array($lane, ['pgsql', 'mysql', 'mariadb'], true))
            ->and($capabilities->supportsReadPast())->toBe($lane === 'sqlsrv')
            ->and($capabilities->mariaDb)->toBe($lane === 'mariadb');

        if ($capabilities->supportsSkipLocked()) {
            $schema = $connection->getSchemaBuilder();
            $schema->dropIfExists('firefly_capability_probe');
            $schema->create('firefly_capability_probe', static function (Blueprint $table): void {
                $table->bigIncrements('id');
            });
            $connection->table('firefly_capability_probe')->insert(['id' => 1]);

            $rows = $connection->transaction(static fn () => $connection->table('firefly_capability_probe')->lock('for update skip locked')->get());

            expect($rows)->toHaveCount(1);
            $schema->drop('firefly_capability_probe');
        }

        $connection->disconnect();
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
