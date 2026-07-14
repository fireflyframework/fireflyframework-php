<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;
use Illuminate\Contracts\Container\ContextualAttribute;

/**
 * Injects a resolved value into a constructor parameter: #[Value('${DB_HOST:localhost}')] string $host.
 * Resolution is delegated to the container-bound ValueResolver.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Value implements ContextualAttribute
{
    public function __construct(public string $expression) {}
}
