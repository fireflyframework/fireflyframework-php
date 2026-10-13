<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(OutboxDatabaseIntegrationTestCase::class);

/**
 * Regression: the sqlite lane's migrate:fresh truncates the database file, so a WAL connection the previous test's
 * application left open over it made every SECOND sqlite boot in a process fail with "database is locked". The trait
 * now disconnects the lane in tearDown; booting the same file twice in a row proves it, and the
 * single row each boot finds proves the second migrate:fresh really wiped the first one's.
 */
it('boots the sqlite lane again after a previous test used it', function (string $lane) {
    /** @var OutboxDatabaseIntegrationTestCase $this */
    DB::table('wallets')->insert(['id' => 'wlt-reboot-'.uniqid(), 'owner_id' => 'owner-reboot', 'currency' => 'EUR', 'balance_minor' => 0]);

    expect(DB::connection()->getName())->toBe('lumen-it')
        ->and(Schema::hasTable('wallets'))->toBeTrue()
        ->and(DB::table('wallets')->count())->toBe(1);
})->with(['first boot' => ['sqlite'], 'second boot' => ['sqlite']])->group('integration');
