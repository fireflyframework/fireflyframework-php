<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\AttributeFixture;

/**
 * The body of a successful stock read — a RESPONSE payload, which nothing in the route manifest can point at,
 * because RouteDescriptor records a status and never a shape. It reaches the document only because an
 * #[ApiResponse] names it, which is the case the attribute exists for.
 */
final class StockLevel
{
    public function __construct(
        public readonly string $sku,
        public readonly int $onHand,
    ) {}
}
