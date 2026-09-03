<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Valid;

/**
 * The root of the nested-body fixture graph: a scalar, a #[Valid] nested DTO (itself holding another DTO),
 * a list of DTOs, and an optional scalar. Exactly the shape that used to reach
 * `new MoneyTransferRequest(...)` with a raw array in $beneficiary and die with a TypeError.
 */
final class MoneyTransferRequest
{
    /**
     * @param  list<TransferLine>  $lines
     */
    public function __construct(
        public readonly int $amount,
        #[Valid]
        public readonly AddressPayload $beneficiary,
        public readonly array $lines = [],
        public readonly ?string $reference = null,
    ) {}
}
