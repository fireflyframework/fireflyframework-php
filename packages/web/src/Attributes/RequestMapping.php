<?php

declare(strict_types=1);

namespace Firefly\Web\Attributes;

use Attribute;

/** Class-level base path prepended to every method mapping on the controller. */
#[Attribute(Attribute::TARGET_CLASS)]
final class RequestMapping
{
    public function __construct(public readonly string $path = '') {}
}
