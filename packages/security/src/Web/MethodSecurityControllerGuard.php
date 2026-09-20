<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\MethodSecurityRefusal;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Web\Security\ControllerSecurityGuard;
use Psr\Log\LoggerInterface;

/**
 * The real dispatch-time guard: looks up the controller method's rule in the compiled method-security manifest
 * and evaluates it (no-eval) against the current SecurityContext, binding the resolved positional args to their
 * parameter names for #param references. A method with no rule is allowed (method security is additive over the
 * HttpSecurityFilter's deny-by-default URL rules). A denial is a 401 when anonymous, a 403 when authenticated —
 * decided by MethodSecurityEvaluator, the join the CQRS enforcer and the proxy interceptor share, and worded by
 * MethodSecurityRefusal, which is where the class name stopped reaching the wire. afterInvocation() applies
 * #[PostAuthorize]/#[PostFilter] to the handler's result.
 */
final class MethodSecurityControllerGuard implements ControllerSecurityGuard
{
    /** The framework's refusal sentence; the rule's own `message` replaces it when the attribute has one. */
    public const string REFUSAL = MethodSecurityRefusal::SENTENCE;

    private readonly MethodSecurityEvaluator $evaluator;

    public function __construct(
        private readonly SecurityMethodManifest $methods,
        SecurityExpressionEvaluator $evaluator,
        RoleHierarchy $roleHierarchy,
        PermissionEvaluator $permissionEvaluator,
        ?LoggerInterface $logger = null,
        ?AuthenticationEventPublisher $events = null,
    ) {
        $this->evaluator = new MethodSecurityEvaluator($evaluator, $roleHierarchy, $permissionEvaluator, $events, $logger);
    }

    public function check(string $controllerClass, string $method, array $args): void
    {
        $rule = $this->methods->ruleFor($controllerClass, $method);
        if ($rule === null) {
            return;
        }

        $this->evaluator->before($rule, $this->evaluator->bind($rule, $args));
    }

    public function afterInvocation(string $controllerClass, string $method, array $args, mixed $result): mixed
    {
        $rule = $this->methods->ruleFor($controllerClass, $method);
        if ($rule === null) {
            return $result;
        }

        return $this->evaluator->after($rule, $this->evaluator->bind($rule, $args), $result);
    }
}
