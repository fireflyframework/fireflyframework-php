<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Repository\Locking\OptimisticLockException;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Repository\Record;
use Firefly\Data\Tests\Fixtures\Repository\RecordRepository;
use Firefly\Data\Tests\Fixtures\Repository\VersionedRecord;
use Firefly\Data\Tests\Fixtures\Repository\VersionedRecordRepository;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Kernel\Exception\Infrastructure\CannotAcquireLockException;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(DatabaseTestCase::class);

/**
 * #[Lock] and HasOptimisticLock against real servers: a PESSIMISTIC_WRITE / PESSIMISTIC_READ read inside a transaction
 * really holds the row (another session's write times out and translates to CannotAcquireLockException), and a stale
 * versioned save is refused everywhere. SQLite has no row locks — its pessimistic modes compile to a plain read, as the
 * PessimisticLockTest unit test documents — so it runs the optimistic case only.
 */
function integrationLockingRepository(): RecordRepository
{
    return new RecordRepository((new TransactionalScanner)->scan([
        'Firefly\\Data\\Tests\\Fixtures\\Repository\\' => dirname(__DIR__).'/Fixtures/Repository',
    ]));
}

function lockingLane(string $lane): Connection
{
    $overrides = $lane === 'sqlite' ? ['database' => IntegrationDsn::sqlitePath('locking')] : [];
    config([
        'database.connections.it_owner' => IntegrationDsn::connectionConfig($lane, $overrides),
        'database.connections.it_other' => IntegrationDsn::connectionConfig($lane, $overrides),
    ]);
    DB::setDefaultConnection('it_owner');
    Schema::dropIfExists('records');
    Schema::dropIfExists('versioned_records');
    Schema::create('records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('status');
        $table->integer('amount');
        $table->string('email')->nullable();
    });
    Schema::create('versioned_records', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('name');
        $table->integer('version')->default(0);
    });

    return DB::connection('it_other');
}

function lockingWriteFromOther(Connection $other, string $lane): ?Throwable
{
    match ($lane) {
        'pgsql' => $other->statement("set lock_timeout = '300ms'"),
        'mysql', 'mariadb' => $other->statement('set session innodb_lock_wait_timeout = 1'),
        'sqlsrv' => $other->unprepared('set lock_timeout 300'), // a prepared SET is undone when sp_prepexec returns
        default => null,
    };

    try {
        $other->table('records')->where('id', 1)->update(['status' => 'stolen']);
    } catch (Throwable $e) {
        return (new PersistenceExceptionTranslator)->translate($e, $other->getDriverName());
    }

    return null;
}

foreach (['pgsql', 'mysql', 'mariadb', 'sqlsrv'] as $lane) {
    it("holds the row under PESSIMISTIC_WRITE and PESSIMISTIC_READ on {$lane}", function () use ($lane): void {
        $other = lockingLane($lane);
        Record::query()->create(['status' => 'open', 'amount' => 50, 'email' => 'a@x.test']);
        $repository = integrationLockingRepository();

        try {
            DB::transaction(function () use ($repository, $other, $lane): void {
                expect($repository->findByIdForUpdate(1)?->email)->toBe('a@x.test')
                    ->and(lockingWriteFromOther($other, $lane))->toBeInstanceOf(CannotAcquireLockException::class);
            });
            DB::transaction(function () use ($repository, $other, $lane): void {
                expect($repository->findByAmountBetween(1, 100))->toHaveCount(1)
                    ->and(lockingWriteFromOther($other, $lane))->toBeInstanceOf(CannotAcquireLockException::class);
            });

            // Outside the transaction the row is free again.
            expect(lockingWriteFromOther($other, $lane))->toBeNull();
        } finally {
            Schema::dropIfExists('records');
            Schema::dropIfExists('versioned_records');
            DB::connection('it_owner')->disconnect();
            $other->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}

foreach (IntegrationDsn::lanes() as $lane) {
    it("refuses a stale versioned save on {$lane}", function () use ($lane): void {
        $other = lockingLane($lane);

        try {
            $repository = new VersionedRecordRepository;
            $id = $repository->save(new VersionedRecord(['name' => 'orig']))->id;
            $first = $repository->findById($id);
            $second = $repository->findById($id);
            assert($first instanceof VersionedRecord && $second instanceof VersionedRecord);

            $first->name = 'first';
            $repository->save($first);
            $second->name = 'second';

            expect(fn () => $repository->save($second))->toThrow(OptimisticLockException::class)
                ->and($repository->findById($id)?->name)->toBe('first')
                ->and($repository->findById($id)?->version)->toEqual(1);
        } finally {
            Schema::dropIfExists('records');
            Schema::dropIfExists('versioned_records');
            DB::connection('it_owner')->disconnect();
            $other->disconnect();
        }
    })->group('integration')->skip(IntegrationDsn::skipReason($lane) !== null, (string) IntegrationDsn::skipReason($lane));
}
