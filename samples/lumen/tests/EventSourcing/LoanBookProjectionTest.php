<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Security\Core\SecurityContextHolder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Loan\LoanBookProjection;
use Lumen\Application\Loan\RepayLoan;

uses(LumenEventSourcingTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('projects the loan events into the loan book and keeps its checkpoint in the database', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);
    $this->commands()->send(new RepayLoan($loanId, 30000));

    // The events are stored; the loan book has not been told yet.
    expect(DB::table(LoanBookProjection::TABLE)->count())->toBe(0);

    $exit = Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('lumen.loan-book: caught-up, 2 event(s) handled, at position 2')
        ->and((array) DB::table(LoanBookProjection::TABLE)->where('loan_id', $loanId)->first())->toMatchArray([
            'wallet_id' => $walletId,
            'principal_minor' => 90000,
            'repaid_minor' => 30000,
            'outstanding_minor' => 60000,
            'repayments' => 1,
            'status' => 'open',
        ])
        ->and(DB::table(EventSourcingSchema::CHECKPOINTS)->where('projection', LoanBookProjection::NAME)->value('position'))->toEqual(2);
})->group('lumen');

it('catches up from its checkpoint: a second run hands over only the new events', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $loanId = $this->grantLoan($this->openWallet(), 60000);
    Artisan::call('firefly:eventsourcing:projections:run');

    $this->commands()->send(new RepayLoan($loanId, 60000));
    Artisan::call('firefly:eventsourcing:projections:run');

    expect(Artisan::output())->toContain('lumen.loan-book: caught-up, 2 event(s) handled, at position 3')
        ->and(DB::table(LoanBookProjection::TABLE)->where('loan_id', $loanId)->value('status'))->toBe('settled');
})->group('lumen');

it('lists the projection with its position, the head and its lag', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $this->grantLoan($this->openWallet(), 10000);

    Artisan::call('firefly:eventsourcing:projections');
    $before = Artisan::output();
    Artisan::call('firefly:eventsourcing:projections:run');
    Artisan::call('firefly:eventsourcing:projections');

    expect($before)->toMatch('/lumen\.loan-book\s*\|\s*catch-up\s*\|\s*0\s*\|\s*1\s*\|\s*1\s*\|\s*ok/')
        ->and(Artisan::output())->toMatch('/lumen\.loan-book\s*\|\s*catch-up\s*\|\s*1\s*\|\s*1\s*\|\s*0\s*\|\s*ok/');
})->group('lumen');

it('rebuilds the loan book from position 0', function () {
    /** @var LumenEventSourcingTestCase $this */
    $this->signInAdmin();
    $loanId = $this->grantLoan($this->openWallet(), 40000);
    $this->commands()->send(new RepayLoan($loanId, 10000));
    Artisan::call('firefly:eventsourcing:projections:run');
    DB::table(LoanBookProjection::TABLE)->delete();

    $exit = Artisan::call('firefly:eventsourcing:projections:rebuild', ['projection' => LoanBookProjection::NAME]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('lumen.loan-book rebuilt: 2 event(s) handled, at position 2')
        ->and(DB::table(LoanBookProjection::TABLE)->where('loan_id', $loanId)->value('outstanding_minor'))->toEqual(30000);
})->group('lumen');
