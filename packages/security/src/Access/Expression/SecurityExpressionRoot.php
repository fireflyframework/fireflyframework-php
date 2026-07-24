<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Expression;

use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;

/**
 * The evaluation context the whitelist evaluator dispatches against — the ONLY object whose methods an
 * expression can call. Every function name in the grammar maps 1:1 to a public method here; there is no way to
 * reach anything else (no property access, no arbitrary calls). hasRole/hasAuthority test membership in the
 * role-hierarchy-expanded authority set; hasRole normalises a bare `ADMIN` to `ROLE_ADMIN`. #param references
 * resolve through arg().
 */
final class SecurityExpressionRoot
{
    /** @var list<string> */
    private readonly array $reachable;

    /**
     * @param  array<string,mixed>  $args  method-argument name => value (for #param references)
     */
    public function __construct(
        private readonly Authentication $authentication,
        RoleHierarchy $roleHierarchy,
        private readonly PermissionEvaluator $permissionEvaluator,
        private readonly array $args,
    ) {
        $this->reachable = $roleHierarchy->reachableAuthorities($authentication->authorityStrings());
    }

    public function hasRole(string $role): bool
    {
        return $this->hasAuthority(str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.$role);
    }

    public function hasAnyRole(string ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function hasAuthority(string $authority): bool
    {
        return in_array($authority, $this->reachable, true);
    }

    public function hasAnyAuthority(string ...$authorities): bool
    {
        foreach ($authorities as $authority) {
            if ($this->hasAuthority($authority)) {
                return true;
            }
        }

        return false;
    }

    public function hasPermission(mixed $target, string $permission): bool
    {
        return $this->permissionEvaluator->hasPermission($this->authentication, $target, $permission);
    }

    public function isAuthenticated(): bool
    {
        return $this->authentication->isAuthenticated();
    }

    public function permitAll(): bool
    {
        return true;
    }

    public function denyAll(): bool
    {
        return false;
    }

    public function arg(string $name): mixed
    {
        return $this->args[$name] ?? null;
    }
}
