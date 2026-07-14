<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

/**
 * Ordering precedence: LOWER order sorts first (Spring convention). Default 0.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Order
{
    public function __construct(public int $order = 0) {}
}
