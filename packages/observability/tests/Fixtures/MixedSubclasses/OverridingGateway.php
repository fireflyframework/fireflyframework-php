<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\MixedSubclasses;

use Firefly\Container\Attributes\Service;

/** The sibling that overrides `charge()` without repeating the attribute, and is therefore unmetered. */
#[Service]
class OverridingGateway extends BaseGateway
{
    public function charge(int $cents): int
    {
        return $cents * 2;
    }
}
