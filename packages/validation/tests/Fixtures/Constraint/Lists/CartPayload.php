<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint\Lists;

use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotEmpty;
use Firefly\Validation\Constraint\Size;
use Firefly\Validation\Valid;

/** The skeleton's OrderRequest shape: a list of DTOs whose element class the constructor docblock states. */
final class CartPayload
{
    /**
     * @param  list<LinePayload>  $lines
     */
    public function __construct(
        #[NotBlank]
        public readonly string $customer,
        #[NotEmpty]
        #[Size(min: 1, max: 50)]
        #[Valid]
        public readonly array $lines,
    ) {}
}
