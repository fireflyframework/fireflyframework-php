<?php

declare(strict_types=1);

namespace Lumen\Tests\Integration;

use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\EventSourcing\Event\NewEvent;
use Firefly\EventSourcing\Snapshot\SnapshotStore;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\EventSourcing\Store\EventStore;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Testing\Integration\IntegrationDsn;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Loan\LoanBookProjection;
use Lumen\Application\Loan\RepayLoan;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;
use Lumen\Domain\Loan\Loan;
use Lumen\Domain\Money;
use Lumen\Infrastructure\Loan\LoanRepository;
use Lumen\Tests\EventSourcing\LumenEventSourcingOutboxTestCase;

uses(LoanLedgerIntegrationTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * The event-sourced loan on a REAL server per lane — PostgreSQL, MySQL, MariaDB, SQL Server (CI) and a SQLite file:
 * the events, the snapshot, the outbox rows and the wallet commit together in each command's transaction; the global
 * stream is numbered in commit order on that server; the projection and its checkpoint commit together. Every read
 * happens after commit, from this process; integers read back from a table are compared loosely (pdo_sqlsrv answers
 * numeric strings).
 */
it('grants, repays and settles a loan with its events, snapshot, outbox rows and loan book', function (string $lane) {
    /** @var LoanLedgerIntegrationTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);
    foreach ([30000, 30000, 30000] as $amountMinor) {
        $this->commands()->send(new RepayLoan($loanId, $amountMinor));
    }

    $loan = $this->app()->make(LoanRepository::class)->get($loanId);
    $exit = Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect(DB::connection()->getName())->toBe('lumen-it')
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->orderBy('aggregate_version')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid', 'loan.repaid', 'loan.repaid', 'loan.settled'])
        ->and($this->app()->make(SnapshotStore::class)->latest($loanId)?->version)->toBe(3)
        ->and([$loan->isSettled(), $loan->committedVersion()])->toBe([true, 5])
        ->and(DB::table(OutboxSchema::TABLE)->where('destination', LumenEventSourcingOutboxTestCase::DESTINATION)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid', 'loan.repaid', 'loan.repaid', 'loan.settled'])
        ->and($exit)->toBe(0)
        ->and((array) DB::table(LoanBookProjection::TABLE)->where('loan_id', $loanId)->first())->toMatchArray([
            'repaid_minor' => 90000,
            'outstanding_minor' => 0,
            'repayments' => 3,
            'status' => 'settled',
        ])
        ->and($this->queries()->ask(new GetBalance($walletId)))->toEqual(0);
})->with(IntegrationDsn::lanes())->group('integration');

it('reads a version-1 repayment as version 2 on every server', function (string $lane) {
    /** @var LoanLedgerIntegrationTestCase $this */
    $loans = $this->app()->make(LoanRepository::class);
    $loans->save(Loan::grant('loan-legacy-it', 'wlt-legacy-it', new Money(50000, Currency::EUR)));
    $this->app()->make(EventStore::class)->append('loan-legacy-it', Loan::aggregateType(), [
        new NewEvent('loan.repaid', ['loanId' => 'loan-legacy-it', 'amount' => 20000, 'outstandingMinor' => 30000], 1),
    ], 1);

    Artisan::call('firefly:eventsourcing:projections:run', ['projection' => LoanBookProjection::NAME]);

    expect($loans->get('loan-legacy-it')->repaidMinor())->toBe(20000)
        ->and(DB::table(LoanBookProjection::TABLE)->where('loan_id', 'loan-legacy-it')->value('repaid_minor'))->toEqual(20000);
})->with(IntegrationDsn::lanes())->group('integration');
