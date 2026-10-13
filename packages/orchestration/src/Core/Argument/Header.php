<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects one execution header, or null when it was not sent. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Header
{
    public function __construct(public string $name) {}
}
