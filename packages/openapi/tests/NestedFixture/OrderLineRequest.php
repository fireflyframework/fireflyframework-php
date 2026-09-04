<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\NestedFixture;

use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Valid;

/** One line of an order. */
final class OrderLineRequest
{
    /**
     * @param  list<LineOptionRequest>  $options  Per-line options, each surcharged separately.
     */
    public function __construct(
        #[NotBlank] public readonly string $sku,
        #[Min(1)] public readonly int $quantity,
        public readonly Fulfilment $fulfilment,
        #[Valid] public readonly array $options = [],
    ) {}
}
