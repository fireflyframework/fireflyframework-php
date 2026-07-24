<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Web\Security\ControllerSecurityGuard;

/**
 * The real dispatch-time guard: looks up the controller method's rule in the compiled method-security manifest
 * and evaluates it (no-eval) against the current SecurityContext, binding the resolved positional args to their
 * parameter names for #param references. A method with no rule is allowed (method security is additive over the
 * HttpSecurityFilter's deny-by-default URL rules). A denial is a 401 when anonymous, a 403 when authenticated.
 */
final class MethodSecurityControllerGuard implements ControllerSecurityGuard
{
    public function __construct(
        private readonly SecurityMethodManifest $methods,
        private readonly SecurityExpressionEvaluator $evaluator,
        private readonly RoleHierarchy $roleHierarchy,
        private readonly PermissionEvaluator $permissionEvaluator,
    ) {}

    public function check(string $controllerClass, string $method, array $args): void
    {
        $rule = $this->methods->ruleFor($controllerClass, $method);
        if ($rule === null) {
            return;
        }

        $named = [];
        foreach ($rule->params as $index => $name) {
            if (array_key_exists($index, $args)) {
                $named[$name] = $args[$index];
            }
        }

        $context = SecurityContextHolder::getContext();
        $authentication = $context->getAuthentication() ?? Authentication::unauthenticated('anonymous', 'anonymous', null);
        $root = new SecurityExpressionRoot($authentication, $this->roleHierarchy, $this->permissionEvaluator, $named);

        if ($this->evaluator->evaluate($rule->expression, $root)) {
            return;
        }

        throw $context->isAuthenticated()
            ? new AuthorizationException("Access is denied for [{$controllerClass}::{$method}].")
            : new AuthenticationException('Authentication is required.');
    }
}
