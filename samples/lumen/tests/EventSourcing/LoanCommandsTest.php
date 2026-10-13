<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Loan\RepayLoan;
use Lumen\Application\Query\GetBalance;
use Lumen\Infrastructure\Loan\LoanRepository;
use Lumen\Security\WalletPermissionEvaluator;

uses(LumenEventSourcingTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('grants a loan: one event in the store and the principal in the wallet', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();

    $loanId = $this->grantLoan($walletId, 90000);

    expect($this->queries()->ask(new GetBalance($walletId)))->toBe(90000)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->pluck('event_type')->all())->toBe(['loan.granted'])
        ->and($this->app()->make(LoanRepository::class)->get($loanId)->outstanding()->minorUnits)->toBe(90000);
})->group('lumen');

it('repays in parts, and the repayment that clears the balance settles the loan', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);

    $outstanding = array_map(fn (int $amountMinor): mixed => $this->commands()->send(new RepayLoan($loanId, $amountMinor)), [30000, 30000, 30000]);

    expect($outstanding)->toBe([60000, 30000, 0])
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->orderBy('aggregate_version')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid', 'loan.repaid', 'loan.repaid', 'loan.settled'])
        ->and($this->app()->make(LoanRepository::class)->get($loanId)->isSettled())->toBeTrue()
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(0);
})->group('lumen');

it('rolls the repayment back with the wallet when the wallet cannot pay it', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);
    $this->commands()->send(new Withdraw($walletId, 85000));

    $caught = null;
    try {
        $this->commands()->send(new RepayLoan($loanId, 30000));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    // The repayment was appended, then the wallet refused the withdrawal: one transaction, so both are undone.
    expect($caught?->getPrevious())->toBeInstanceOf(ConflictException::class)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->count())->toBe(1)
        ->and($this->app()->make(LoanRepository::class)->get($loanId)->outstanding()->minorUnits)->toBe(90000)
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(5000);
})->group('lumen');

it('refuses a repayment larger than what is outstanding with a 409', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet(50000);
    $loanId = $this->grantLoan($walletId, 10000);

    $caught = null;
    try {
        $this->commands()->send(new RepayLoan($loanId, 20000));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    expect($caught?->getPrevious())->toBeInstanceOf(ConflictException::class)
        ->and($caught?->getPrevious()?->getMessage())->toContain('is outstanding')
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->count())->toBe(1);
})->group('lumen');

it('lets the owner of the loan\'s wallet repay it', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet(10000);
    $loanId = $this->grantLoan($walletId, 90000);

    $this->signInWalletOwner('owner-loans');

    expect($this->commands()->send(new RepayLoan($loanId, 30000)))->toBe(60000)
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(70000);
})->group('lumen');

it('refuses a repayment from a WALLET_OWNER who does not own the loan\'s wallet, appending and debiting nothing', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet(10000);
    $loanId = $this->grantLoan($walletId, 90000);

    // Same role, someone else's loan: hasPermission(#command, 'debit') loads the loan, finds its wallet, and that
    // wallet's owner is owner-loans, not mallory.
    $this->signInWalletOwner('mallory');

    $caught = null;
    try {
        $this->commands()->send(new RepayLoan($loanId, 30000));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    expect($caught?->getPrevious())->toBeInstanceOf(AuthorizationException::class)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->count())->toBe(1)
        ->and($this->app()->make(LoanRepository::class)->get($loanId)->outstanding()->minorUnits)->toBe(90000)
        ->and($this->queries()->ask(new GetBalance($walletId)))->toBe(100000);
})->group('lumen');

it('refuses a WALLET_OWNER\'s repayment of a loan that does not exist with a 403, not a 404', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInWalletOwner('owner-loans');

    $caught = null;
    try {
        $this->commands()->send(new RepayLoan('loan-nope', 100));
    } catch (CommandProcessingException $e) {
        $caught = $e;
    }

    // Fail closed: an unknown loan has no owner to match, so the evaluator denies; the caller learns nothing about
    // which loan ids exist. An ADMIN asking the same reaches the handler and gets its not-found.
    expect($caught?->getPrevious())->toBeInstanceOf(AuthorizationException::class);
})->group('lumen');

it('replaces the deny-all default with WalletPermissionEvaluator, which reads a repayment\'s wallet from the loan', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);

    /** @var PermissionEvaluator $evaluator */
    $evaluator = $this->fireflyContext()->get(PermissionEvaluator::class);
    $owner = fn (string $name): Authentication => Authentication::authenticated($name, $name, [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')]);

    expect($evaluator)->toBeInstanceOf(WalletPermissionEvaluator::class)
        ->and($evaluator->hasPermission($owner('owner-loans'), new RepayLoan($loanId, 100), 'debit'))->toBeTrue()
        ->and($evaluator->hasPermission($owner('mallory'), new RepayLoan($loanId, 100), 'debit'))->toBeFalse()
        ->and($evaluator->hasPermission($owner('owner-loans'), new RepayLoan($loanId, 100), 'read'))->toBeFalse()
        ->and($evaluator->hasPermission($owner('owner-loans'), new RepayLoan('loan-nope', 100), 'debit'))->toBeFalse();
})->group('lumen');
