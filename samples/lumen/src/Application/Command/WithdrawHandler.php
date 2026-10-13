<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Security\Access\Attributes\PreAuthorize;
use Lumen\Domain\Money;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles Withdraw: loads the aggregate, debits the amount (the domain enforces no-overdraw — an over-balance debit
 * raises a ConflictException that rolls the transaction back and surfaces as a CommandProcessingException), persists,
 * and returns the new balance in minor units. #[Transactional] for the same reason as the sibling handlers.
 *
 * Guarded by #[PreAuthorize]: an ADMIN may debit any wallet; a WALLET_OWNER only the wallet it owns. The role alone
 * is not ownership — any customer holds it — so the rule also asks hasPermission(#command, 'debit'), which the
 * framework hands, with the Withdraw bound to #command, to the application's PermissionEvaluator:
 * WalletPermissionEvaluator loads the wallet and grants the debit only when its owner_id is the principal's name.
 * SecurityExpressionEvaluator is a CLOSED whitelist (booleans and/or/not, string literals, #param, and a fixed set of
 * functions — no `==`, no `.property` navigation), so the comparison cannot be written in the expression; the
 * evaluator is where it lives. SecurityCommandAuthorizer enforces this at the bus BEFORE the handler runs; a denied
 * withdraw surfaces as CommandProcessingException wrapping AuthorizationException (403) for a signed-in principal
 * without the role or without the wallet, or AuthenticationException (401) when there is no principal at all.
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler.
 */
#[CommandHandler]
class WithdrawHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[PreAuthorize("hasRole('ADMIN') or (hasRole('WALLET_OWNER') and hasPermission(#command, 'debit'))")]
    #[Transactional]
    public function handle(Withdraw $command): int
    {
        $wallet = $this->wallets->findById($command->walletId)
            ?? throw new ResourceNotFoundException("Wallet [{$command->walletId}] not found");

        $wallet->withdraw(new Money($command->amountMinor, $wallet->currency()));
        $this->wallets->save($wallet);

        return $wallet->balanceMoney()->minorUnits;
    }
}
