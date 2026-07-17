<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class QueryParam
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly mixed $default = null,
        public readonly bool $required = false,
    ) {}
}
