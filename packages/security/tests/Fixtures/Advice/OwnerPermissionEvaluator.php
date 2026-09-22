<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Core\Authentication;

/** Grants a Report to its owner, and an int id when it is even; denies everything else. */
final class OwnerPermissionEvaluator implements PermissionEvaluator
{
    public function hasPermission(Authentication $authentication, mixed $target, string $permission): bool
    {
        if ($target instanceof Report) {
            return $target->owner === $authentication->getName();
        }

        return is_int($target) && $target % 2 === 0;
    }
}
