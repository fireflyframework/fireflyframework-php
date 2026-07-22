<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Data\Tests\Fixtures\Capstone\AccountService;
use Firefly\Data\Tests\Fixtures\Capstone\IgnorableException;
use Firefly\Data\Tests\Support\DataCapstoneTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

uses(DataCapstoneTestCase::class);

/**
 * Resolve the (proxied) AccountService from the booted context. Takes the app explicitly — a top-level Pest
 * helper is NOT bound to the TestCase, so it cannot read the protected $this->app itself; each it() passes it in
 * via $this->capstoneApp() (inside an it() closure $this IS the TestCase, and capstoneApp() narrows the untyped
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
    $service = accountService($this->capstoneApp());

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
    accountService($this->capstoneApp())->transferAndCommit();

    expect(DB::table('accounts')->count())->toBe(2);
});

it('commits despite a method-level noRollbackFor exception (override beats class-level)', function () {
    /** @var DataCapstoneTestCase $this */
    try {
        accountService($this->capstoneApp())->logButKeep();
    } catch (IgnorableException) {
    }

    // Class-level default would roll back any Throwable; the method-level override keeps the row.
    expect(DB::table('accounts')->where('name', 'kept')->count())->toBe(1);
});

it('unwinds a NESTED inner rollback to a savepoint, leaving the outer row intact', function () {
    /** @var DataCapstoneTestCase $this */
    accountService($this->capstoneApp())->outerWithNested();

    expect(DB::table('accounts')->pluck('name')->all())->toBe(['outer']);
});
