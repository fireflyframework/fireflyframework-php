<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

use Firefly\Security\Core\Authentication;

/** Backs hasPermission(target, permission) in expressions. */
interface PermissionEvaluator
{
    public function hasPermission(Authentication $authentication, mixed $target, string $permission): bool;
}
