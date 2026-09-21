<?php

declare(strict_types=1);

use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\TransactionPhase;
use Firefly\Data\Transaction\TransactionSynchronizationRegistry;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

it('reports no active transaction outside one, and the default connection\'s inside a plain DB::transaction', function () {
    $registry = new TransactionSynchronizationRegistry;

    expect($registry->isTransactionActive())->toBeFalse()
        ->and($registry->currentConnection())->toBeNull();

    DB::transaction(function () use ($registry): void {
        expect($registry->isTransactionActive())->toBeTrue();
    });
});

it('runs BEFORE_COMMIT inside the commit, then AFTER_COMMIT and AFTER_COMPLETION, and never AFTER_ROLLBACK', function () {
    $registry = new TransactionSynchronizationRegistry;
    $log = [];

    DB::beginTransaction();
    DB::table('widgets')->insert(['name' => 'a']);
    $registry->register(TransactionPhase::AFTER_COMMIT, function () use (&$log): void {
        $log[] = 'after-commit:'.DB::connection()->transactionLevel();
    });
    $registry->register(TransactionPhase::AFTER_ROLLBACK, function () use (&$log): void {
        $log[] = 'after-rollback';
    });
    $registry->register(TransactionPhase::AFTER_COMPLETION, function () use (&$log): void {
        $log[] = 'after-completion';
    });
    $registry->register(TransactionPhase::BEFORE_COMMIT, function () use (&$log): void {
        $log[] = 'before-commit:'.DB::connection()->transactionLevel();
    });
    DB::commit();

    expect($log)->toBe(['before-commit:1', 'after-commit:0', 'after-completion']);
});

it('runs AFTER_ROLLBACK and AFTER_COMPLETION on rollback and discards BEFORE_COMMIT', function () {
    $registry = new TransactionSynchronizationRegistry;
    $log = [];

    DB::beginTransaction();
    $registry->register(TransactionPhase::BEFORE_COMMIT, function () use (&$log): void {
        $log[] = 'before-commit';
    });
    $registry->register(TransactionPhase::AFTER_COMMIT, function () use (&$log): void {
        $log[] = 'after-commit';
    });
    $registry->register(TransactionPhase::AFTER_ROLLBACK, function () use (&$log): void {
        $log[] = 'after-rollback';
    });
    $registry->register(TransactionPhase::AFTER_COMPLETION, function () use (&$log): void {
        $log[] = 'after-completion';
    });
    DB::rollBack();

    expect($log)->toBe(['after-rollback', 'after-completion']);

    // The discarded BEFORE_COMMIT must not replay on the NEXT transaction's commit.
    DB::beginTransaction();
    DB::commit();
    expect($log)->toBe(['after-rollback', 'after-completion']);
});

it('lets a BEFORE_COMMIT that throws abort the template\'s commit: rolled back, rethrown, level 0', function () {
    $registry = new TransactionSynchronizationRegistry;
    $template = new TransactionTemplate(null, null, null, $registry);
    $rolledBack = false;

    try {
        $template->execute(function () use ($registry, &$rolledBack): void {
            DB::table('widgets')->insert(['name' => 'doomed']);
            $registry->register(TransactionPhase::AFTER_ROLLBACK, function () use (&$rolledBack): void {
                $rolledBack = true;
            });
            $registry->register(TransactionPhase::BEFORE_COMMIT, static function (): void {
                throw new LogicException('not this one');
            });
        });
        $this->fail('expected the veto to propagate');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe('not this one');
    }

    expect(DB::table('widgets')->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and($rolledBack)->toBeTrue();
});

it('tracks the template\'s connection frame so a listener binds to the transaction that is actually open', function () {
    $registry = new TransactionSynchronizationRegistry;
    $template = new TransactionTemplate(null, null, null, $registry);
    $seen = null;

    $template->execute(function () use ($registry, &$seen): void {
        $seen = $registry->currentConnection();
    });

    expect($seen)->toBe('testing')
        ->and($registry->currentConnection())->toBeNull();
});

it('runs a BEFORE_COMMIT registered by a BEFORE_COMMIT callback inside the SAME commit, never the next one', function () {
    $registry = new TransactionSynchronizationRegistry;
    $log = [];

    DB::beginTransaction();
    $registry->register(TransactionPhase::BEFORE_COMMIT, function () use ($registry, &$log): void {
        $log[] = 'outer:'.DB::connection()->transactionLevel();

        // What a BEFORE_COMMIT listener that publishes an event with its own BEFORE_COMMIT listener does: the
        // wiring pass sees the transaction still active (Laravel fires TransactionCommitting at level 1) and
        // registers again while the queue is being drained.
        $registry->register(TransactionPhase::BEFORE_COMMIT, function () use ($registry, &$log): void {
            $log[] = 'inner:'.DB::connection()->transactionLevel();

            $registry->register(TransactionPhase::BEFORE_COMMIT, function () use (&$log): void {
                $log[] = 'innermost:'.DB::connection()->transactionLevel();
            });
        });
    });
    DB::commit();

    expect($log)->toBe(['outer:1', 'inner:1', 'innermost:1']);

    // An unrelated transaction on the same connection must not inherit anything.
    DB::beginTransaction();
    DB::table('widgets')->insert(['name' => 'unrelated']);
    DB::commit();

    expect($log)->toBe(['outer:1', 'inner:1', 'innermost:1']);
});

it('sweeps a BEFORE_COMMIT queued mid-drain by a callback that then vetoes, so the rollback discards it too', function () {
    $registry = new TransactionSynchronizationRegistry;
    $template = new TransactionTemplate(null, null, null, $registry);
    $log = [];

    try {
        $template->execute(function () use ($registry, &$log): void {
            DB::table('widgets')->insert(['name' => 'doomed']);
            $registry->register(TransactionPhase::BEFORE_COMMIT, function () use ($registry, &$log): void {
                $log[] = 'outer';
                $registry->register(TransactionPhase::BEFORE_COMMIT, function () use (&$log): void {
                    $log[] = 'inner';
                });

                throw new LogicException('veto after re-registering');
            });
        });
        $this->fail('expected the veto to propagate');
    } catch (LogicException $e) {
        expect($e->getMessage())->toBe('veto after re-registering');
    }

    expect($log)->toBe(['outer'])
        ->and(DB::connection()->transactionLevel())->toBe(0)
        ->and(DB::table('widgets')->count())->toBe(0);

    $template->execute(function (): void {
        DB::table('widgets')->insert(['name' => 'unrelated']);
    });

    expect($log)->toBe(['outer']);
});
