<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\MixedSubclasses;

use Firefly\Container\Attributes\Service;

/** The sibling that overrides `charge()` without repeating the attribute, and is therefore unguarded. */
#[Service]
class OverridingGateway extends BaseGateway
{
    public function charge(string $account): string
    {
        return strtoupper($account);
    }
}
