<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\CachedAdvice;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Tests\Fixtures\Advice\OwnerPermissionEvaluator;

/**
 * Hands the cached boot the same owner-based `hasPermission()` semantics Advice/AdviceSecurityConfiguration
 * gives the uncached one (see that class for why a competing bean DEFINITION is the override seam), so the
 * compiled proxy's filters can be seen to narrow rather than blanket-deny. Carries no rule of its own.
 */
#[Configuration]
final class CachedAdviceSecurityConfiguration
{
    #[Bean]
    public function permissionEvaluator(): PermissionEvaluator
    {
        return new OwnerPermissionEvaluator;
    }
}
