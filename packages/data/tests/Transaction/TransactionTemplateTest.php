<?php

declare(strict_types=1);

use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\Exception\TransactionNotAllowedException;
use Firefly\Data\Transaction\Exception\TransactionRequiredException;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Support\Facades\DB;

uses(DatabaseTestCase::class);

it('rolls back every write when the work throws', function () {
    $template = new TransactionTemplate;

    try {
        $template->execute(function (): void {
            DB::table('widgets')->insert(['name' => 'a']);
            DB::table('widgets')->insert(['name' => 'b']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('widgets')->count())->toBe(0);
});

it('commits and persists on success', function () {
    (new TransactionTemplate)->execute(function (): void {
        DB::table('widgets')->insert(['name' => 'a']);
        DB::table('widgets')->insert(['name' => 'b']);
    });

    expect(DB::table('widgets')->count())->toBe(2);
});

it('commits despite an exception listed in noRollbackFor', function () {
    $template = new TransactionTemplate;
    $descriptor = new TransactionalDescriptor(noRollbackFor: [RuntimeException::class]);

    try {
        $template->execute(function (): void {
            DB::table('widgets')->insert(['name' => 'kept']);
            throw new RuntimeException('ignored');
        }, $descriptor);
    } catch (RuntimeException) {
    }

    expect(DB::table('widgets')->count())->toBe(1);
});

it('throws TransactionRequiredException for MANDATORY without an active transaction', function () {
    $template = new TransactionTemplate;

    expect(fn () => $template->execute(fn () => 'x', new TransactionalDescriptor(propagation: Propagation::MANDATORY)))
        ->toThrow(TransactionRequiredException::class);
});

it('throws TransactionNotAllowedException for NEVER inside an active transaction', function () {
    $template = new TransactionTemplate;

    DB::beginTransaction();

    try {
        expect(fn () => $template->execute(fn () => 'x', new TransactionalDescriptor(propagation: Propagation::NEVER)))
            ->toThrow(TransactionNotAllowedException::class);
    } finally {
        DB::rollBack();
    }
});

it('unwinds a NESTED inner rollback to a savepoint, leaving the outer rows intact', function () {
    $template = new TransactionTemplate;

    $template->execute(function () use ($template): void {
        DB::table('widgets')->insert(['name' => 'outer']);

        try {
            $template->execute(function (): void {
                DB::table('widgets')->insert(['name' => 'inner']);
                throw new RuntimeException('inner fail');
            }, new TransactionalDescriptor(propagation: Propagation::NESTED));
        } catch (RuntimeException) {
        }
    });

    expect(DB::table('widgets')->pluck('name')->all())->toBe(['outer']);
});

it('delegates from the interceptor to the template', function () {
    $interceptor = new TransactionInterceptor(new TransactionTemplate);

    $result = $interceptor->run(function (): string {
        DB::table('widgets')->insert(['name' => 'via-interceptor']);

        return 'ok';
    }, new TransactionalDescriptor);

    expect($result)->toBe('ok')->and(DB::table('widgets')->count())->toBe(1);
});

it('runs SUPPORTS work as-is without opening a transaction when none is active', function () {
    $template = new TransactionTemplate;
    $levelInside = -1;

    $result = $template->execute(function () use (&$levelInside): string {
        $levelInside = DB::connection()->transactionLevel();
        DB::table('widgets')->insert(['name' => 'supports']);

        return 'r';
    }, new TransactionalDescriptor(propagation: Propagation::SUPPORTS));

    expect($levelInside)->toBe(0)->and($result)->toBe('r')->and(DB::table('widgets')->count())->toBe(1);
});

it('runs NOT_SUPPORTED work without opening a new transaction', function () {
    $template = new TransactionTemplate;
    $levelInside = -1;

    $template->execute(function () use (&$levelInside): void {
        $levelInside = DB::connection()->transactionLevel();
        DB::table('widgets')->insert(['name' => 'not-supported']);
    }, new TransactionalDescriptor(propagation: Propagation::NOT_SUPPORTED));

    expect($levelInside)->toBe(0)->and(DB::table('widgets')->count())->toBe(1);
});

it('opens an independent transaction for REQUIRES_NEW and rolls it back on failure', function () {
    $template = new TransactionTemplate;
    $levelInside = -1;

    try {
        $template->execute(function () use (&$levelInside): void {
            $levelInside = DB::connection()->transactionLevel();
            DB::table('widgets')->insert(['name' => 'req-new']);
            throw new RuntimeException('boom');
        }, new TransactionalDescriptor(propagation: Propagation::REQUIRES_NEW));
    } catch (RuntimeException) {
    }

    expect($levelInside)->toBe(1)->and(DB::table('widgets')->count())->toBe(0);
});
