<?php

declare(strict_types=1);

use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\Exception\TransactionSystemException;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Kernel\Exception\Infrastructure\DataIntegrityViolationException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(DatabaseTestCase::class);

/**
 * The commit that itself fails, provoked for real rather than with a Connection double: a `DEFERRABLE INITIALLY
 * DEFERRED` foreign key with `PRAGMA foreign_keys = ON` lets the INSERT succeed and reports the violation at
 * COMMIT — and sqlite, like Postgres, leaves the transaction OPEN after that failed COMMIT (Laravel's level is
 * still 1, PDO::inTransaction() still true), which is exactly the state TransactionTemplate::commit() exists to
 * unwind. The rollback is observed three ways: the connection's TransactionRolledBack event, the level, and PDO.
 */
beforeEach(function (): void {
    DB::statement('pragma foreign_keys = on');
    DB::statement('create table parents (id integer primary key)');
    DB::statement('create table children (id integer primary key, parent_id integer references parents(id) deferrable initially deferred)');
});

/**
 * Counts the connection's TransactionRolledBack events from now on — the rollBack() a failed commit must trigger.
 * The connection dispatches through the application's real dispatcher (Event::fake() would not reach it).
 *
 * @return Closure(): int
 */
function observeRollbacks(): Closure
{
    $count = 0;
    Event::listen(TransactionRolledBack::class, function () use (&$count): void {
        $count++;
    });

    return static function () use (&$count): int { // a by-reference `use`: an arrow fn would freeze the 0
        return $count;
    };
}

it('rolls back a commit that fails, leaves no transaction open, and throws the translated failure', function () {
    $template = new TransactionTemplate;
    $rollbacks = observeRollbacks();

    try {
        $template->execute(function (): void {
            DB::table('children')->insert(['parent_id' => 999]); // accepted: the check is deferred to COMMIT
        });
        $this->fail('expected the deferred foreign key to fail the commit');
    } catch (DataIntegrityViolationException $e) {
        expect($e->getPrevious())->toBeInstanceOf(PDOException::class)
            ->and($e->getPrevious()?->getMessage())->toContain('FOREIGN KEY constraint failed')
            ->and($e->extensions())->toBe(['sqlState' => '23000']);
    }

    expect($rollbacks())->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('children')->count())->toBe(0);
});

it('keeps the application exception when the commit-and-rethrow of a noRollbackFor match fails to commit', function () {
    $template = new TransactionTemplate;
    $rollbacks = observeRollbacks();
    $kept = new RuntimeException('the method threw, the rules said keep it');

    // The closure always throws, so execute() cannot return here: the catch is the only way out (no fail() needed).
    try {
        $template->execute(function () use ($kept): void {
            DB::table('children')->insert(['parent_id' => 999]);

            throw $kept;
        }, new TransactionalDescriptor(noRollbackFor: [RuntimeException::class]));
    } catch (TransactionSystemException $e) {
        expect($e->applicationException)->toBe($kept)
            ->and($e->errorCode())->toBe('TRANSACTION_SYSTEM_ERROR')
            ->and($e->getPrevious())->toBeInstanceOf(DataIntegrityViolationException::class)
            ->and($e->getPrevious()?->getPrevious())->toBeInstanceOf(PDOException::class);
    }

    expect($rollbacks())->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
        ->and(DB::table('children')->count())->toBe(0);
});

it('carries the TRANSLATED application exception when the kept failure was a driver failure', function () {
    $template = new TransactionTemplate;
    $rollbacks = observeRollbacks();
    DB::table('widgets')->insert(['id' => 1, 'name' => 'a']);

    try {
        $template->execute(function (): void {
            DB::table('children')->insert(['parent_id' => 999]);
            DB::table('widgets')->insert(['id' => 1, 'name' => 'dup']); // a DuplicateKeyException the rules keep
        }, new TransactionalDescriptor(noRollbackFor: [DuplicateKeyException::class]));
        $this->fail('expected a TransactionSystemException');
    } catch (TransactionSystemException $e) {
        expect($e->applicationException)->toBeInstanceOf(DuplicateKeyException::class)
            ->and($e->applicationException->getPrevious())->toBeInstanceOf(QueryException::class)
            ->and($e->getPrevious())->toBeInstanceOf(DataIntegrityViolationException::class);
    }

    expect($rollbacks())->toBe(1)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::table('children')->count())->toBe(0);
});

it('does not intervene when the commit succeeds after a noRollbackFor match (commit-and-rethrow as documented)', function () {
    $template = new TransactionTemplate;
    $rollbacks = observeRollbacks();

    try {
        $template->execute(function (): void {
            DB::table('parents')->insert(['id' => 7]);
            DB::table('children')->insert(['parent_id' => 7]);

            throw new RuntimeException('kept');
        }, new TransactionalDescriptor(noRollbackFor: [RuntimeException::class]));
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('kept');
    }

    expect($rollbacks())->toBe(0)
        ->and(DB::table('children')->count())->toBe(1);
});
