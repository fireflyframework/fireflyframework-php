<?php

declare(strict_types=1);

namespace Lumen\Application\Payout;

use Firefly\Container\Attributes\Service;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Orchestration\Core\Argument\FromStep;
use Firefly\Orchestration\Core\Argument\Input;
use Firefly\Orchestration\Saga\Attributes\Saga;
use Firefly\Orchestration\Saga\Attributes\SagaStep;
use Lumen\Application\Command\Deposit;
use Lumen\Application\Command\Withdraw;

/**
 * Pays money out of a wallet to a bank account: the one Lumen flow that spans two systems, so no #[Transactional]
 * boundary can make it atomic. Each step is its own unit of work; each step that changed something has a
 * compensation, and a failure undoes the completed steps newest first (the default strict-sequential policy).
 *
 *   debit   -> Withdraw through the bus (its own transaction, its own #[PreAuthorize]); undone by refund (a Deposit)
 *   submit  -> the bank's reference for the transfer;                                    undone by cancel
 *   confirm -> the bank executes it; the last step, so nothing after it needs undoing
 *
 * Input: walletId, iban, amountMinor.
 */
#[Service]
#[Saga(name: 'payout')]
final class PayoutSaga
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly BankGateway $bank,
    ) {}

    #[SagaStep(id: 'debit', compensate: 'refund')]
    public function debit(#[Input('walletId')] string $walletId, #[Input('amountMinor')] int $amountMinor): int
    {
        $balance = $this->commands->send(new Withdraw($walletId, $amountMinor));

        return is_int($balance) ? $balance : 0;
    }

    public function refund(#[Input('walletId')] string $walletId, #[Input('amountMinor')] int $amountMinor): void
    {
        $this->commands->send(new Deposit($walletId, $amountMinor));
    }

    #[SagaStep(id: 'submit', dependsOn: ['debit'], compensate: 'cancel')]
    public function submit(#[Input('iban')] string $iban, #[Input('amountMinor')] int $amountMinor): string
    {
        return $this->bank->submit($iban, $amountMinor);
    }

    public function cancel(string $reference): void
    {
        $this->bank->cancel($reference);
    }

    #[SagaStep(id: 'confirm', dependsOn: ['submit'])]
    public function confirm(#[FromStep('submit')] string $reference): string
    {
        $this->bank->confirm($reference);

        return $reference;
    }
}
