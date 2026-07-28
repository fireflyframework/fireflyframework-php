<?php

declare(strict_types=1);

namespace Lumen\Application\Command;

/**
 * Command: debit `amountMinor` (minor units) from an existing wallet. The domain enforces the no-overdraw invariant;
 * an over-balance withdrawal raises a ConflictException that surfaces through the bus as a CommandProcessingException.
 */
final readonly class Withdraw
{
    public function __construct(
        public string $walletId,
        public int $amountMinor,
    ) {}
}
