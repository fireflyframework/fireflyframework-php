<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/** Requires ANY of the listed authorities (JSR-250 / Spring @Secured). Compiled to hasAnyAuthority(...). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Secured
{
    /** @var list<string> */
    public array $authorities;

    public function __construct(string ...$authorities)
    {
        $this->authorities = array_values($authorities);
    }
}
