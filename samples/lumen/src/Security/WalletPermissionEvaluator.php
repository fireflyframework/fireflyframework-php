<?php

declare(strict_types=1);

namespace Lumen\Security;

use Firefly\Container\Attributes\Component;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Core\Authentication;
use Illuminate\Contracts\Container\Container;
use Lumen\Application\Command\Transfer;
use Lumen\Application\Command\Withdraw;
use Lumen\Application\Loan\RepayLoan;
use Lumen\Infrastructure\Loan\LoanRepository;
use Lumen\Infrastructure\WalletRepository;

/**
 * Backs `hasPermission(#command, 'debit')`: a principal may debit a wallet only when it owns it. The target is the
 * command the bus is about to dispatch — a Withdraw names its wallet, a Transfer debits its source wallet (never its
 * destination), a RepayLoan names a loan whose wallet is read from the event store — and the debit is granted only when that wallet's owner_id equals the authenticated
 * principal's name. Everything else fails closed: a permission other than `debit`, a target of any other type, a
 * wallet or loan that does not exist, an unauthenticated principal (whom the bus still answers with a 401, not a 403).
 *
 * Being a PermissionEvaluator bean is the whole registration: SecurityAutoConfiguration binds its deny-all default
 * only #[ConditionalOnMissingBean(PermissionEvaluator::class)], so this component displaces it.
 *
 * The loan repository is looked up when a repayment is checked, not injected: the security beans that hold this
 * evaluator are built at boot in every harness, while LoanRepository is #[Lazy] and can only be built where
 * firefly/eventsourcing's providers boot. A RepayLoan never reaches the bus anywhere else.
 */
#[Component]
final class WalletPermissionEvaluator implements PermissionEvaluator
{
    public const string DEBIT = 'debit';

    public function __construct(
        private readonly WalletRepository $wallets,
        private readonly Container $container,
    ) {}

    public function hasPermission(Authentication $authentication, mixed $target, string $permission): bool
    {
        if ($permission !== self::DEBIT || ! $authentication->isAuthenticated()) {
            return false;
        }

        $walletId = match (true) {
            $target instanceof Withdraw => $target->walletId,
            $target instanceof Transfer => $target->sourceWalletId,
            $target instanceof RepayLoan => $this->walletOfLoan($target->loanId),
            default => null,
        };
        if ($walletId === null) {
            return false;
        }

        $owner = $this->wallets->findById($walletId)?->ownerId() ?? '';

        return $owner !== '' && $owner === $authentication->getName();
    }

    private function walletOfLoan(string $loanId): ?string
    {
        $loans = $this->container->make(LoanRepository::class);

        return $loans->find($loanId)?->walletId();
    }
}
