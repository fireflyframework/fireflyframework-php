<?php

declare(strict_types=1);

namespace Firefly\Security\Cqrs;

use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;

/**
 * The shared join both bus authorizers delegate to: resolve the message's handler (class + method) from the CQRS
 * HandlerManifest, look up its method-security rule in the SecurityMethodManifest, and evaluate it against the
 * current SecurityContext with the message bound to the handler's single #param. A message with no handler, or a
 * handler with no rule, is allowed — method security is additive, not a second deny-by-default gate (that is the
 * HttpSecurityFilter's job). A failing rule throws the kernel AuthorizationException (403).
 */
final class MethodSecurityMessageEnforcer
{
    /** @var array<string,array{class:string,method:string}> messageClass => handler */
    private array $handlerByMessage = [];

    public function __construct(
        HandlerManifest $handlers,
        private readonly SecurityMethodManifest $methods,
        private readonly SecurityExpressionEvaluator $evaluator,
        private readonly RoleHierarchy $roleHierarchy,
        private readonly PermissionEvaluator $permissionEvaluator,
    ) {
        foreach ($handlers->handlers() as $descriptor) {
            $this->handlerByMessage[$descriptor->kind->value.':'.$descriptor->messageClass] = [
                'class' => $descriptor->handlerClass,
                'method' => $descriptor->method,
            ];
        }
    }

    public function enforce(object $message, HandlerKind $kind): void
    {
        $handler = $this->handlerByMessage[$kind->value.':'.$message::class] ?? null;
        if ($handler === null) {
            return;
        }

        $rule = $this->methods->ruleFor($handler['class'], $handler['method']);
        if ($rule === null) {
            return;
        }

        $args = isset($rule->params[0]) ? [$rule->params[0] => $message] : [];
        $authentication = SecurityContextHolder::getAuthentication() ?? Authentication::unauthenticated('anonymous', 'anonymous', null);
        $root = new SecurityExpressionRoot($authentication, $this->roleHierarchy, $this->permissionEvaluator, $args);

        if (! $this->evaluator->evaluate($rule->expression, $root)) {
            throw new AuthorizationException("Access is denied for [{$handler['class']}::{$handler['method']}].");
        }
    }
}
