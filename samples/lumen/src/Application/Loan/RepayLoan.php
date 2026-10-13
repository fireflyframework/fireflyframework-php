<?php

declare(strict_types=1);

namespace Lumen\Application\Loan;

/** Command: repay `amountMinor` of a loan from the wallet it was granted into. */
final readonly class RepayLoan
{
    public function __construct(
        public string $loanId,
        public int $amountMinor,
    ) {}
}
