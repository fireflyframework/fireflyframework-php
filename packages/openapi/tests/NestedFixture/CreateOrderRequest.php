<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\NestedFixture;

use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Valid;

/**
 * The request body that reproduced the defect this fixture family exists for: `lines` documented itself as
 * `{"type": "array", "default": {}}` — no `items`, no OrderLineRequest component anywhere in the document,
 * and an empty PHP array encoded as a JSON OBJECT.
 *
 * Every element type here is stated in the constructor docblock, which is exactly where RouteScanner already
 * reads it from to hydrate the payload, so nothing about this class is new information to the framework.
 */
final class CreateOrderRequest
{
    /**
     * @param  list<OrderLineRequest>  $lines  The lines to order, at least one.
     * @param  list<Fulfilment>  $channels
     */
    public function __construct(
        #[NotBlank] #[Size(min: 3, max: 40)] public readonly string $reference,
        #[NotBlank] #[Email] public readonly string $customerEmail,
        #[Min(1)] public readonly int $totalMinor,
        #[Valid] public readonly array $lines = [],
        public readonly array $channels = [],
        public readonly ?CategoryNode $catalogue = null,
    ) {}
}
