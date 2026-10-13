<?php

declare(strict_types=1);

namespace Lumen\Application\Loan;

use Firefly\Container\Attributes\Lazy;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Lumen\Domain\Money;
use Lumen\Infrastructure\Loan\LoanRepository;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles RepayLoan: replays the loan, records the repayment (and the settlement when it clears the balance), appends
 * it, then withdraws the amount from the wallet — answering what is still outstanding. The append comes first on
 * purpose: when the wallet cannot pay, Wallet::withdraw() throws AFTER the events were appended, and the one
 * #[Transactional] boundary rolls them back with everything else. Guarded like Withdraw. #[Lazy] and not final for
 * GrantLoanHandler's reasons.
 */
#[CommandHandler]
#[Lazy]
class RepayLoanHandler
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly LoanRepository $loans,
    ) {}

    #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")]
    #[Transactional]
    public function handle(RepayLoan $command): int
    {
        $loan = $this->loans->get($command->loanId);
        $wallet = $this->wallets->findById($loan->walletId())
            ?? throw new ResourceNotFoundException("Wallet [{$loan->walletId()}] not found");

        $amount = new Money($command->amountMinor, $loan->currency());
        $loan->repay($amount);
        $this->loans->save($loan);

        $wallet->withdraw($amount);
        $this->wallets->save($wallet);

        return $loan->outstanding()->minorUnits;
    }
}
