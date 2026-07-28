<?php

declare(strict_types=1);

namespace Lumen\Application\Query;

use Firefly\Cqrs\Attributes\QueryHandler;
use Lumen\Infrastructure\WalletRepository;

/**
 * Handles GetBalance: reads the aggregate through the port and returns its balance in minor units, or null when no
 * such wallet exists. A pure read — no #[Transactional]; the query type is inferred from the handle() parameter.
 */
#[QueryHandler]
final class GetBalanceHandler
{
    public function __construct(private readonly WalletRepository $wallets) {}

    public function handle(GetBalance $query): ?int
    {
        $wallet = $this->wallets->findById($query->walletId);

        return $wallet?->balanceMoney()->minorUnits;
    }
}
