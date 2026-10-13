<?php

declare(strict_types=1);

namespace Lumen\Tests\EventSourcing;

use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\EventSourcing\Store\EventSourcingSchema;
use Firefly\Security\Core\SecurityContextHolder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Loan\RepayLoan;

uses(LumenEventSourcingOutboxTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('writes each loan event to the outbox in the transaction that appends it, and a consumer delivers it', function () {
    /** @var LumenEventSourcingOutboxTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);
    $this->commands()->send(new RepayLoan($loanId, 30000));

    $headers = DB::table(OutboxSchema::TABLE)->where('event_type', 'loan.granted')->value('headers');

    expect(DB::table(OutboxSchema::TABLE)->where('destination', LumenEventSourcingOutboxTestCase::DESTINATION)->orderBy('id')->pluck('event_type')->all())
        ->toBe(['loan.granted', 'loan.repaid'])
        ->and(DB::table(OutboxSchema::TABLE)->where('destination', 'wallet.events')->orderBy('id')->pluck('event_type')->all())
        ->toBe(['WalletOpened', 'FundsDeposited', 'FundsWithdrawn'])
        ->and(is_string($headers) ? json_decode($headers, true) : null)->toHaveKey('x-aggregate-id', $loanId)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PENDING)->count())->toBe(5);

    $exit = Artisan::call('firefly:eda:consume', ['--max-messages' => 5, '--time-limit' => 10, '--poll-timeout' => 100]);

    expect($exit)->toBe(0)
        ->and(DB::table(OutboxSchema::TABLE)->where('status', OutboxSchema::STATUS_PUBLISHED)->count())->toBe(5);
})->group('lumen');

it('rolls the outbox row back with the event when the wallet cannot pay the repayment', function () {
    /** @var LumenEventSourcingOutboxTestCase $this */
    $this->signInAdmin();
    $walletId = $this->openWallet();
    $loanId = $this->grantLoan($walletId, 90000);
    $this->commands()->send(new Withdraw($walletId, 85000));

    expect(fn () => $this->commands()->send(new RepayLoan($loanId, 30000)))->toThrow(CommandProcessingException::class)
        ->and(DB::table(OutboxSchema::TABLE)->where('destination', LumenEventSourcingOutboxTestCase::DESTINATION)->pluck('event_type')->all())->toBe(['loan.granted'])
        ->and(DB::table(EventSourcingSchema::EVENTS)->where('aggregate_id', $loanId)->count())->toBe(1);
})->group('lumen');
