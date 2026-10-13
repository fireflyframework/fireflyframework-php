<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Transfer: an ATOMIC debit(source) + credit(destination) + save(both) inside one #[Transactional] boundary.
 *
 * The transferred value carries the SOURCE currency (a transfer moves a specific amount of money, not an abstract
 * number of minor units), so the credit leg deposits that same Money into the destination. When the two wallets share
 * a currency the credit succeeds and both saves commit together; when they differ the destination's deposit() throws
 * a currency-mismatch ConflictException AFTER the source was already debited, and — because #[Transactional] rolls
 * back on any Throwable — the source debit is undone too. That is the money-cannot-vanish invariant: there is no path
 * where the source loses funds the destination never receives.
 *
 * Guarded by the same #[PreAuthorize] as WithdrawHandler, because a transfer IS a debit: an ADMIN may move money out
 * of any wallet, a WALLET_OWNER only out of the wallet it owns. WalletPermissionEvaluator reads the Transfer bound to
 * #command and checks the owner of its SOURCE wallet — owning the destination grants nothing, or anyone could pull
 * money out of any wallet by naming it as the source. SecurityCommandAuthorizer enforces the rule at the bus before
 * the handler runs, so a refused transfer touches neither wallet.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler (same reason as the S4 handlers).
 */
#[CommandHandler]
class TransferHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[PreAuthorize("hasRole('ADMIN') or (hasRole('WALLET_OWNER') and hasPermission(#command, 'debit'))")]
    #[Transactional(propagation: Propagation::REQUIRED)]
    public function handle(Transfer $command): void
    {
        $source = $this->wallets->findById($command->sourceWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->sourceWalletId}] not found");
        $destination = $this->wallets->findById($command->destinationWalletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->destinationWalletId}] not found");

        $amount = new Money($command->amountMinor, $source->currency());
        $source->withdraw($amount);       // debit (raises FundsWithdrawn)
        $this->wallets->save($source);    // persist + track the debit INSIDE the tx, so it can genuinely roll back
        $destination->deposit($amount);   // credit — throws on currency mismatch -> whole tx rolls back
        $this->wallets->save($destination);
        $source->recordTransferTo($command->destinationWalletId, $amount); // both legs succeeded -> raise TransferCompleted
        // commit here -> FundsWithdrawn + FundsDeposited + TransferCompleted drain atomically after the unit of work commits.
        // (recordTransferTo runs only on the success path: a failed credit throws above, the tx rolls back, nothing publishes.)
    }
}
