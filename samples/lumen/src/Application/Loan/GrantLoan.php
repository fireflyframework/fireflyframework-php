<?php

declare(strict_types=1);

namespace Lumen\Application\Loan;

/** Command: lend `principalMinor` (minor units) to an existing wallet, in the wallet's own currency. */
final readonly class GrantLoan
{
    public function __construct(
        public string $walletId,
        public int $principalMinor,
    ) {}
}
