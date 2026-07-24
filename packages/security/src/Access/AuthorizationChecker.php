<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;

/**
 * The imperative entry point for authorization checks at arbitrary call sites (Spring's AuthorizationChecker).
 * Evaluates an expression against the CURRENT holder context via the same no-eval evaluator the filters and
 * method-security use. isGranted() is a boolean probe; check() enforces fail-closed — a denial is a 401 when the
 * context is anonymous (authenticate first) and a 403 when authenticated but under-privileged.
 */
final class AuthorizationChecker
{
    public function __construct(
        private readonly SecurityExpressionEvaluator $evaluator,
        private readonly RoleHierarchy $roleHierarchy,
        private readonly PermissionEvaluator $permissionEvaluator,
    ) {}

    /**
     * @param  array<string,mixed>  $args
     */
    public function isGranted(string $expression, array $args = []): bool
    {
        $authentication = SecurityContextHolder::getAuthentication() ?? Authentication::unauthenticated('anonymous', 'anonymous', null);
        $root = new SecurityExpressionRoot($authentication, $this->roleHierarchy, $this->permissionEvaluator, $args);

        return $this->evaluator->evaluate($expression, $root);
    }

    /**
     * @param  array<string,mixed>  $args
     */
    public function check(string $expression, array $args = []): void
    {
        if ($this->isGranted($expression, $args)) {
            return;
        }

        throw SecurityContextHolder::getContext()->isAuthenticated()
            ? new AuthorizationException('Access is denied.')
            : new AuthenticationException('Authentication is required.');
    }

    public function isAuthenticated(): bool
    {
        return SecurityContextHolder::getContext()->isAuthenticated();
    }
}
