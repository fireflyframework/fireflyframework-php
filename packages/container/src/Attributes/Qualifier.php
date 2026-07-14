<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class Qualifier
{
    public function __construct(public string $name) {}
}
