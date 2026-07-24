<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

use Firefly\Security\Core\Authentication;

/** The fail-closed default: no PermissionEvaluator configured means hasPermission is always denied. */
final class DenyAllPermissionEvaluator implements PermissionEvaluator
{
    public function hasPermission(Authentication $authentication, mixed $target, string $permission): bool
    {
        return false;
    }
}
