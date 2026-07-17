<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class RequestHeader
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly mixed $default = null,
    ) {}
}
