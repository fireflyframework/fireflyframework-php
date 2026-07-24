<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/** Requires ANY of the listed roles (JSR-250 @RolesAllowed). Compiled to hasAnyRole(...). */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class RolesAllowed
{
    /** @var list<string> */
    public array $roles;

    public function __construct(string ...$roles)
    {
        $this->roles = array_values($roles);
    }
}
