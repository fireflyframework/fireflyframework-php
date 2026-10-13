<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\OrchestrationSchema;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Support\Facades\DB;
use LogicException;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\OpenWallet;
use Lumen\Application\Query\GetBalance;
use Lumen\Domain\Currency;
use Lumen\Infrastructure\Payout\SimulatedBankGateway;

uses(LumenOrchestrationTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/** Signs a wallet owner in: the saga's debit step is a Withdraw, which #[PreAuthorize] guards at the bus. */
function lumenSignInPayoutOwner(): void
{
    SecurityContextHolder::setContext(new SecurityContext(
        Authentication::authenticated('owner-payout', 'owner-payout', [new SimpleGrantedAuthority('ROLE_WALLET_OWNER')])
    ));
}

/** Opens a EUR wallet holding $balanceMinor and returns its id. */
function lumenFundedWallet(CommandBus $commands, int $balanceMinor): string
{
    $id = $commands->send(new OpenWallet('owner-payout', Currency::EUR));
    if (! is_string($id)) {
        throw new LogicException('OpenWallet answers the new wallet id.');
    }
    $commands->send(new Deposit($id, $balanceMinor));

    return $id;
}

it('pays out: debits the wallet, then submits and confirms the bank transfer', function () {
    /** @var LumenOrchestrationTestCase $this */
    lumenSignInPayoutOwner();
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);
    $id = lumenFundedWallet($commands, 50000);

    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $id, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 20000]);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(ExecutionStatus::Completed)
        ->and($this->app()->make(SimulatedBankGateway::class)->calls)->toBe(['submit bank-1', 'confirm bank-1'])
        ->and($queries->ask(new GetBalance($id)))->toBe(30000)
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('COMPLETED');
})->group('lumen');

it('refunds the wallet when the bank refuses the account', function () {
    /** @var LumenOrchestrationTestCase $this */
    lumenSignInPayoutOwner();
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);
    $id = lumenFundedWallet($commands, 50000);

    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $id, 'iban' => 'XX0012345678', 'amountMinor' => 20000]);

    expect($result->success)->toBeFalse()
        ->and($result->firstErrorStepId())->toBe('submit')
        ->and($result->compensatedSteps())->toBe(['debit'])
        ->and($this->app()->make(SimulatedBankGateway::class)->calls)->toBe(['refused XX0012345678'])
        ->and($queries->ask(new GetBalance($id)))->toBe(50000)
        ->and(DB::table(OrchestrationSchema::DEAD_LETTERS)->where('correlation_id', $result->correlationId)->value('step_id'))->toBe('submit');
})->group('lumen');

it('cancels the bank transfer, then refunds the wallet, when the confirmation fails', function () {
    /** @var LumenOrchestrationTestCase $this */
    lumenSignInPayoutOwner();
    /** @var CommandBus $commands */
    $commands = $this->fireflyContext()->get(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = $this->fireflyContext()->get(QueryBus::class);
    $id = lumenFundedWallet($commands, 200000);

    // 1,500.00 is over the simulated bank's daily limit: submitted, then refused at confirmation.
    $result = $this->app()->make(SagaEngine::class)->execute('payout', ['walletId' => $id, 'iban' => 'ES9121000418450200051332', 'amountMinor' => 150000]);

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(ExecutionStatus::Failed)
        ->and($result->firstErrorStepId())->toBe('confirm')
        ->and($result->compensatedSteps())->toBe(['debit', 'submit'])
        ->and($this->app()->make(SimulatedBankGateway::class)->calls)->toBe(['submit bank-1', 'limit bank-1', 'cancel bank-1'])
        ->and($queries->ask(new GetBalance($id)))->toBe(200000)
        ->and(DB::table(OrchestrationSchema::EXECUTIONS)->where('correlation_id', $result->correlationId)->value('status'))->toBe('FAILED');
})->group('lumen');
