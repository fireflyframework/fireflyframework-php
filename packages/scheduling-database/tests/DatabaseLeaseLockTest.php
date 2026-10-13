<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Scheduling\Database\DatabaseLeaseLock;
use Firefly\Scheduling\Database\LockSchema;
use Firefly\Scheduling\Database\SchedulingDatabaseAutoConfiguration;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Config\Repository;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(FireflyDatabaseTestCase::class);

beforeEach(function (): void {
    $migration = require dirname(__DIR__).'/database/migrations/2026_10_12_000100_create_firefly_scheduling_locks_table.php';

    if (! $migration instanceof Migration || ! method_exists($migration, 'up')) {
        throw new RuntimeException('Expected an executable migration.');
    }

    $migration->up();
});

/** A lock instance ("node") named $owner whose clock reads $now. */
function leaseLockAt(string $owner, string $now): DatabaseLeaseLock
{
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);

    return new DatabaseLeaseLock($connections, null, $owner, static fn (): DateTimeImmutable => new DateTimeImmutable($now, new DateTimeZone('UTC')));
}

it('grants a name to one node at a time and frees it on release', function () {
    $a = leaseLockAt('node-a', '2026-10-12 10:00:00');
    $b = leaseLockAt('node-b', '2026-10-12 10:00:01');

    expect($a->tryAcquire('reconcile', 30))->toBeTrue()
        ->and($b->tryAcquire('reconcile', 30))->toBeFalse()
        ->and($b->tryAcquire('other-job', 30))->toBeTrue()
        ->and(DB::table(LockSchema::TABLE)->where('name', 'reconcile')->value('locked_by'))->toStartWith('node-a/');

    $a->release('reconcile');

    expect(DB::table(LockSchema::TABLE)->where('name', 'reconcile')->exists())->toBeFalse()
        ->and($b->tryAcquire('reconcile', 30))->toBeTrue();
});

it('hands an ended lease to the next node, and the late holder cannot release it', function () {
    $a = leaseLockAt('node-a', '2026-10-12 10:00:00');
    expect($a->tryAcquire('reconcile', 1.5))->toBeTrue();

    expect(leaseLockAt('node-b', '2026-10-12 10:00:01.499')->tryAcquire('reconcile', 30))->toBeFalse();

    $b = leaseLockAt('node-b', '2026-10-12 10:00:01.500');
    expect($b->tryAcquire('reconcile', 30))->toBeTrue()
        ->and(DB::table(LockSchema::TABLE)->where('name', 'reconcile')->value('locked_until'))->toBe('2026-10-12 10:00:31.500');

    // a's job finally ends: its release must not drop b's lease.
    $a->release('reconcile');
    expect(DB::table(LockSchema::TABLE)->where('name', 'reconcile')->value('locked_by'))->toStartWith('node-b/');
});

it('refuses a name longer than the column instead of truncating it', function () {
    expect(fn () => leaseLockAt('a', '2026-10-12 10:00:00')->tryAcquire(str_repeat('x', 256), 1))->toThrow(InvalidArgumentException::class, '255');
});

it('keeps a caller transaction usable when the lock is already held', function () {
    leaseLockAt('node-a', '2026-10-12 10:00:00')->tryAcquire('reconcile', 30);

    DB::transaction(function (): void {
        expect(leaseLockAt('node-b', '2026-10-12 10:00:01')->tryAcquire('reconcile', 30))->toBeFalse()
            ->and(DB::selectOne('select 1 as one'))->not->toBeNull();
    });
});

it('releasing a name it does not hold is a no-op', function () {
    leaseLockAt('node-a', '2026-10-12 10:00:00')->release('never-acquired');

    expect(DB::table(LockSchema::TABLE)->count())->toBe(0);
});

it('the bean uses the default connection when the connection key is unset, null or blank', function (array $lock) {
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);
    $config = new Config(new Repository(['firefly' => ['scheduling' => ['lock' => ['provider' => 'database', ...$lock]]]]));

    $bean = (new SchedulingDatabaseAutoConfiguration)->databaseLeaseLock($config, $connections);

    expect($bean)->toBeInstanceOf(DatabaseLeaseLock::class)
        ->and($bean->tryAcquire('reconcile', 30))->toBeTrue()
        ->and(DB::table(LockSchema::TABLE)->where('name', 'reconcile')->exists())->toBeTrue();
})->with([
    'unset' => [[]],
    'null' => [['database' => ['connection' => null]]],
    'blank' => [['database' => ['connection' => '']]],
]);

it('the bean takes the lease on the connection the key names', function () {
    config()->set('database.connections.locks', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    Schema::connection('locks')->create(LockSchema::TABLE, fn (Blueprint $table) => LockSchema::blueprint($table));
    /** @var ConnectionResolverInterface $connections */
    $connections = App::make(ConnectionResolverInterface::class);
    $config = new Config(new Repository(['firefly' => ['scheduling' => ['lock' => ['provider' => 'database', 'database' => ['connection' => 'locks']]]]]));

    expect((new SchedulingDatabaseAutoConfiguration)->databaseLeaseLock($config, $connections)->tryAcquire('reconcile', 30))->toBeTrue()
        ->and(DB::connection('locks')->table(LockSchema::TABLE)->count())->toBe(1)
        ->and(DB::table(LockSchema::TABLE)->count())->toBe(0);
});
