<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Security\Core\SecurityContextHolder;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Loan\RepayLoan;
use Lumen\Application\Query\GetBalance;
use Lumen\Infrastructure\Loan\LoanRepository;

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
