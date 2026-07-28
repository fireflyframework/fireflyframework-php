<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Deposit: loads the aggregate, credits the amount in the wallet's own currency, persists, and returns the
 * new balance in minor units. #[Transactional] for the same load-bearing reason as OpenWalletHandler — it is the only
 * thing that runs save() at transactionLevel() > 0 so the aggregate is tracked and FundsDeposited publishes on commit.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler.
 */
#[CommandHandler]
class DepositHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[Transactional]
    public function handle(Deposit $command): int
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $wallet->deposit(new Money($command->amountMinor, $wallet->currency()));
        $this->wallets->save($wallet);

        return $wallet->balanceMoney()->minorUnits;
    }
}
