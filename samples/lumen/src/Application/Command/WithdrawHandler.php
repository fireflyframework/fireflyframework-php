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
 * Guarded by #[PreAuthorize]: only an ADMIN or the WALLET_OWNER may debit. SecurityExpressionEvaluator is a CLOSED
 * whitelist (booleans and/or/not, string literals, #param, and eight fixed functions — no `==`, no `.property`
 * navigation), so ownership is modelled as a granted WALLET_OWNER authority rather than an `#command.ownerId ==
 * authentication.name` comparison, which the parser cannot express. SecurityCommandAuthorizer enforces this at the bus
 * BEFORE the handler runs; a denied withdraw surfaces as CommandProcessingException wrapping AuthorizationException.
 * (The faithful owner-only alternative — hasPermission(#command, 'withdraw') backed by a custom PermissionEvaluator
 * bean that loads the wallet and compares its owner_id to the current principal — is left to a later step.)
 *
 * Intentionally NOT final: the generated transactional proxy subclasses this handler.
 */
#[CommandHandler]
class WithdrawHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    #[PreAuthorize("hasRole('ADMIN') or hasRole('WALLET_OWNER')")]
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
