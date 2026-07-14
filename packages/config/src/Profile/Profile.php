<?php

declare(strict_types=1);

namespace Firefly\Config\Profile;

use Attribute;

/**
 * Gates a component to one or more active profiles. The predicate is evaluated by conditional
 * registration (firefly/autoconfigure, M5); defined here so config and later milestones share it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Profile
{
    /** @var list<string> */
    public array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values($names);
    }
}
