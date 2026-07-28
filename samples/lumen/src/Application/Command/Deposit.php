<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

/**
 * Command: credit `amountMinor` (minor units, e.g. cents) to an existing wallet. The currency is the wallet's own —
 * the amount is combined against the persisted balance, so no currency travels on the command.
 */
final readonly class Deposit
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
    ) {}
}
