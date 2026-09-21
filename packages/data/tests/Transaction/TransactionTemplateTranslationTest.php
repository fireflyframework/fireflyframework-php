<?php

declare(strict_types=1);

use Firefly\Data\Exception\PersistenceExceptionTranslator;
use Firefly\Data\Tests\Support\DatabaseTestCase;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Kernel\Exception\Infrastructure\BadSqlGrammarException;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;

uses(DatabaseTestCase::class);

it('translates a raw DB failure inside a REQUIRED transaction and rolls back', function () {
    $template = new TransactionTemplate;

    try {
        $template->execute(function (): void {
            DB::table('widgets')->insert(['id' => 1, 'name' => 'a']);
            DB::table('widgets')->insert(['id' => 1, 'name' => 'b']);
        });
        Assert::fail('expected a DuplicateKeyException');
    } catch (DuplicateKeyException $e) {
        expect($e->getPrevious())->toBeInstanceOf(QueryException::class);
    }

    expect(DB::table('widgets')->count())->toBe(0)
        ->and(DB::connection()->transactionLevel())->toBe(0);
});

it('translates in the arms that open no transaction too (SUPPORTS)', function () {
    expect(fn () => (new TransactionTemplate)->execute(
        fn () => DB::select('select * from no_such_table'),
        new TransactionalDescriptor(propagation: Propagation::SUPPORTS),
    ))->toThrow(BadSqlGrammarException::class);
});

it('matches a noRollbackFor written against the ORIGINAL exception type as well as the translated one', function () {
    $template = new TransactionTemplate;

    try {
        $template->execute(function (): void {
            DB::table('widgets')->insert(['id' => 1, 'name' => 'kept']);
            DB::table('widgets')->insert(['id' => 1, 'name' => 'dup']);
        }, new TransactionalDescriptor(noRollbackFor: [QueryException::class]));
    } catch (DuplicateKeyException) {
    }

    // commit-and-rethrow: the first insert survives, and the thrown type is the translated one.
    expect(DB::table('widgets')->pluck('name')->all())->toBe(['kept']);

    try {
        $template->execute(function (): void {
            DB::table('widgets')->insert(['id' => 2, 'name' => 'kept-too']);
            DB::table('widgets')->insert(['id' => 2, 'name' => 'dup']);
        }, new TransactionalDescriptor(noRollbackFor: [DuplicateKeyException::class]));
    } catch (DuplicateKeyException) {
    }

    expect(DB::table('widgets')->pluck('name')->all())->toBe(['kept', 'kept-too']);
});

it('passes the raw exception through when the translator is disabled', function () {
    $template = new TransactionTemplate(null, new PersistenceExceptionTranslator(enabled: false));

    expect(fn () => $template->execute(function (): void {
        DB::table('widgets')->insert(['id' => 1, 'name' => 'a']);
        DB::table('widgets')->insert(['id' => 1, 'name' => 'b']);
    }))->toThrow(QueryException::class);
});

it('translates through the interceptor the proxies call', function () {
    $interceptor = new TransactionInterceptor(new TransactionTemplate);

    expect(fn () => $interceptor->run(function (): void {
        DB::table('widgets')->insert(['id' => 1, 'name' => 'a']);
        DB::table('widgets')->insert(['id' => 1, 'name' => 'b']);
    }, new TransactionalDescriptor))->toThrow(DuplicateKeyException::class);
});
