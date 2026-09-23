<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\OverriddenBase;

use Firefly\Resilience\Method\Retry;

/**
 * A METHOD-level attribute on a concrete base — the shape InheritedBase accepts — except that the stereotyped
 * child OVERRIDES `charge()` without repeating it. An override carries its own, empty, attribute list, so the
 * child compiles no row here either: the same silent loss as the class-level base beside it, reached the
 * other way, and refused the same way.
 */
class BaseGateway
{
    #[Retry('payments')]
    public function charge(string $account): string
    {
        return $account;
    }
}
