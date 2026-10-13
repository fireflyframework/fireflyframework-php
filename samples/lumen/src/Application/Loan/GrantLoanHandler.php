<?php

declare(strict_types=1);

namespace Lumen\Application\Loan;

use Firefly\Container\Attributes\Lazy;
use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Lumen\Domain\Loan\Loan;
use Lumen\Domain\Money;
use Lumen\Infrastructure\Loan\LoanRepository;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles GrantLoan: appends the loan's first event and pays the principal into the wallet, in ONE #[Transactional]
 * boundary — the event store, the wallet row and (under the database outbox) every outbox row commit together or not
 * at all. Only an ADMIN may lend. #[Lazy] for LoanRepository's reason; NOT final, because the transactional proxy
 * subclasses it.
 */
#[CommandHandler]
#[Lazy]
class GrantLoanHandler
{
    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly LoanRepository $loans,
    ) {}

    #[PreAuthorize("hasRole('ADMIN')")]
    #[Transactional]
    public function handle(GrantLoan $command): string
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $id = 'loan-'.bin2hex(random_bytes(8));
        $principal = new Money($command->principalMinor, $wallet->currency());
        $this->loans->save(Loan::grant($id, $command->walletId, $principal));

        $wallet->deposit($principal);
        $this->wallets->save($wallet);

        return $id;
    }
}
