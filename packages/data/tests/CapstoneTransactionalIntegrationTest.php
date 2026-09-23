<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Tests\Fixtures\Capstone\AccountService;
use Firefly\Data\Tests\Fixtures\Capstone\BeanWiredLedger;
use Firefly\Data\Tests\Fixtures\Capstone\IgnorableException;
use Firefly\Data\Tests\Support\DataCapstoneTestCase;
use Firefly\Kernel\Exception\Infrastructure\DuplicateKeyException;
use Firefly\Kernel\Exception\Infrastructure\TransactionTimedOutException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

uses(DataCapstoneTestCase::class);

/**
 * Resolve the (proxied) AccountService from the booted context. Takes the app explicitly — a top-level Pest
 * helper is NOT bound to the TestCase, so it cannot read the protected $this->app itself; each it() passes it in
 * via $this->app() (inside an it() closure $this IS the TestCase, and app() narrows the untyped
 * inherited $app to a real Application).
 */
function accountService(Application $app): AccountService
{
    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    /** @var AccountService $service */
    $service = $context->get(AccountService::class);

    return $service;
}

it('proxies the #[Service] and rolls back BOTH inserts when the method throws', function () {
    /** @var DataCapstoneTestCase $this */
    $service = accountService($this->app());

    expect($service::class)->not->toBe(AccountService::class); // it is the generated proxy subclass
    expect($service)->toBeInstanceOf(AccountService::class);

    try {
        $service->transferAndFail();
    } catch (RuntimeException) {
    }

    expect(DB::table('accounts')->count())->toBe(0);
});

it('commits and persists both rows', function () {
    /** @var DataCapstoneTestCase $this */
    accountService($this->app())->transferAndCommit();

    expect(DB::table('accounts')->count())->toBe(2);
});

it('commits despite a method-level noRollbackFor exception (override beats class-level)', function () {
    /** @var DataCapstoneTestCase $this */
    try {
        accountService($this->app())->logButKeep();
    } catch (IgnorableException) {
    }

    // Class-level default would roll back any Throwable; the method-level override keeps the row.
    expect(DB::table('accounts')->where('name', 'kept')->count())->toBe(1);
});

it('unwinds a NESTED inner rollback to a savepoint, leaving the outer row intact', function () {
    /** @var DataCapstoneTestCase $this */
    accountService($this->app())->outerWithNested();

    expect(DB::table('accounts')->pluck('name')->all())->toBe(['outer']);
});

it('throws the translated type out of a #[Transactional] method, rolled back', function () {
    /** @var DataCapstoneTestCase $this */
    expect(fn () => accountService($this->app())->insertDuplicate())->toThrow(DuplicateKeyException::class)
        ->and(DB::table('accounts')->count())->toBe(0);
});

it('enforces #[Transactional(timeout:)] through the proxy: overrun, rolled back, 504', function () {
    /** @var DataCapstoneTestCase $this */
    expect(fn () => accountService($this->app())->slowTransfer())->toThrow(TransactionTimedOutException::class)
        ->and(DB::table('accounts')->count())->toBe(0);
});

/*
 | The SECOND wiring shape the chain is installed for. Everything above resolves a #[Service]; a #[Bean] method's
 | declared return type is post-processed too, and that is the whole reason the stereotype is not the rule —
 | ObservabilityMethodScanner declines to refuse a metric attribute on exactly this shape, on exactly this
 | premise. Read off the source the premise is four links long (abstractsToExtend threads $bean->returns as the
 | declared class → the BPP keys hasProxyFor() on it → the plan has a row for it → ProxyFactory::wrap reaches the
 | proxy through newInstanceWithoutConstructor, so the non-autowirable constructor is no obstacle). Below it is a
 | test: the bean comes back as the generated subclass, and its transaction really rolls back.
 */

it('proxies an UNSTEREOTYPED class wired by a #[Bean] method and really runs its transaction', function () {
    /** @var DataCapstoneTestCase $this */
    /** @var ApplicationContext $context */
    $context = $this->app()->make(ApplicationContext::class);

    /** @var BeanWiredLedger $ledger */
    $ledger = $context->get(BeanWiredLedger::class);

    expect($ledger::class)->not->toBe(BeanWiredLedger::class) // the generated proxy subclass
        ->and($ledger)->toBeInstanceOf(BeanWiredLedger::class);

    try {
        $ledger->recordAndFail();
    } catch (RuntimeException) {
    }

    // Two inserts and a throw: without the proxy the first row would have survived on its own autocommit.
    expect(DB::table('accounts')->count())->toBe(0);

    // The commit half, and the proof that the readonly constructor state survived newInstanceWithoutConstructor.
    expect($ledger->recordAndCommit())->toBe('ledger')
        ->and(DB::table('accounts')->pluck('name')->all())->toBe(['ledger']);
});
