<?php

declare(strict_types=1);

namespace Firefly\Security\Cqrs;

use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Psr\Log\LoggerInterface;

/**
 * The shared join both bus authorizers delegate to: resolve the message's handler (class + method) from the CQRS
 * HandlerManifest, look up its method-security rule in the SecurityMethodManifest, and evaluate it against the
 * current SecurityContext with the message bound to the handler's single #param. A message with no handler, or a
 * handler with no rule, is allowed — method security is additive, not a second deny-by-default gate (that is the
 * HttpSecurityFilter's job). A failing rule throws through MethodSecurityEvaluator — a 401 for an anonymous
 * caller (it used to be a 403 here alone), a 403 worded by MethodSecurityRefusal for an authenticated one, so the
 * handler's class name goes to the log and not to the client. The bus authorises BEFORE dispatch, so
 * #[PostAuthorize]/#[PostFilter] on a handler are enforced only when the handler is proxied (see
 * MethodSecurityScanner::scanProxyAdvice()).
 */
final class MethodSecurityMessageEnforcer
{
    /** @var array<string,array{class:string,method:string}> messageClass => handler */
    private array $handlerByMessage = [];

    private readonly MethodSecurityEvaluator $evaluator;

    public function __construct(
        HandlerManifest $handlers,
        private readonly SecurityMethodManifest $methods,
        SecurityExpressionEvaluator $evaluator,
        RoleHierarchy $roleHierarchy,
        PermissionEvaluator $permissionEvaluator,
        ?LoggerInterface $logger = null,
        ?AuthenticationEventPublisher $events = null,
    ) {
        $this->evaluator = new MethodSecurityEvaluator($evaluator, $roleHierarchy, $permissionEvaluator, $events, $logger);
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

        $this->evaluator->before($rule, isset($rule->params[0]) ? [$rule->params[0] => $message] : []);
    }
}
