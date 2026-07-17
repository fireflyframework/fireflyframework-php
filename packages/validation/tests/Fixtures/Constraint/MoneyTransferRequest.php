<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\Iban;
use Firefly\Validation\Constraint\Money;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Valid;

final class MoneyTransferRequest
{
    public function __construct(
        #[NotBlank]
        #[Iban]
        public readonly string $account,
        #[Money]
        public readonly string $amount,
        #[NotBlank]
        #[Size(max: 140)]
        public readonly string $reference,
        #[Valid]
        public readonly AddressPayload $beneficiary,
    ) {}
}
