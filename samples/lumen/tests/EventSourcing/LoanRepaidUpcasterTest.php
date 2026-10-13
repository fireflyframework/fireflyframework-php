<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Loan\LoanBookProjection;
use Lumen\Domain\Currency;
use Lumen\Domain\Loan\Loan;
use Lumen\Domain\Money;
use Lumen\Infrastructure\Loan\LoanRepository;

uses(LumenEventSourcingTestCase::class);

it('reads a version-1 repayment, stored before the field was renamed, as version 2', function () {
    /** @var LumenEventSourcingTestCase $this */
    $loans = $this->app()->make(LoanRepository::class);
    $loans->save(Loan::grant('loan-legacy', 'wlt-legacy', new Money(50000, Currency::EUR)));

    // A repayment exactly as the first loans were stored: version 1, its amount still called `amount`.
    $this->app()->make(EventStore::class)->append('loan-legacy', Loan::aggregateType(), [
        new NewEvent('loan.repaid', ['loanId' => 'loan-legacy', 'amount' => 20000, 'outstandingMinor' => 30000], 1),
    ], 1);

    $loan = $loans->get('loan-legacy');
    Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect([$loan->repaidMinor(), $loan->outstanding()->minorUnits, $loan->repayments()])->toBe([20000, 30000, 1])
        ->and(DB::table(LoanBookProjection::TABLE)->where('loan_id', 'loan-legacy')->value('repaid_minor'))->toEqual(20000)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'loan-legacy')->where('event_type', 'loan.repaid')->value('event_version'))->toEqual(1);
})->group('lumen');

it('stores new repayments as version 2 and reads them untouched', function () {
    /** @var LumenEventSourcingTestCase $this */
    $loans = $this->app()->make(LoanRepository::class);
    $loan = Loan::grant('loan-current', 'wlt-current', new Money(50000, Currency::EUR));
    $loan->repay(new Money(5000, Currency::EUR));
    $loans->save($loan);

    expect($loans->get('loan-current')->repaidMinor())->toBe(5000)
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', 'loan-current')->where('event_type', 'loan.repaid')->value('event_version'))->toEqual(2);
})->group('lumen');
