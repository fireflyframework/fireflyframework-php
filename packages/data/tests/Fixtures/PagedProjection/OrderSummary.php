<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\PagedProjection;

/** A class-based projection over three `orders` columns — the DTO a paged list screen renders. */
final readonly class OrderSummary
{
    public function __construct(
        public int $id,
        public string $customer,
        public int $amount,
    ) {}
}
