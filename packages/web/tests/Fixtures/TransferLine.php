<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Constraint\NotBlank;

/** One element of MoneyTransferRequest::$lines — the list-of-DTOs case. */
final class TransferLine
{
    public function __construct(
        #[NotBlank]
        public readonly string $reference,
        public readonly int $cents,
    ) {}
}
