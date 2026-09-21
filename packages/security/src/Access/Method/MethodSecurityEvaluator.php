<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Method;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Kernel\Exception\Security\SecurityException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Illuminate\Support\Enumerable;
use Psr\Log\LoggerInterface;
use Traversable;

/**
 * The one join every method-security enforcement site delegates to — the controller guard, the CQRS enforcer
 * and the proxy interceptor used to each bind arguments and evaluate the pre rule on their own, and none of
 * them could have grown #[PostAuthorize] or the filters without the three drifting apart.
 *
 * before(): #[PreFilter] narrows the named iterable, then the pre rule runs; a refusal is a 401 when the
 * context is anonymous (authenticate first) and a 403 worded by MethodSecurityRefusal when authenticated,
 * and the 403 publishes an AuthorizationDeniedEvent naming Class::method and the expression.
 * after(): #[PostAuthorize] runs with `#returnObject` bound to the result, then #[PostFilter] narrows it with
 * `#filterObject` bound to each element. Both run against the CURRENT holder context, read fresh per call.
 *
 * Filtering an array keeps keys (a list is re-indexed), an Enumerable is filtered in kind so a Collection
 * stays a Collection, any other Traversable comes back as an array, and a value that is not iterable at all is
 * refused — a filter that cannot filter must fail closed, never hand the whole value through.
 */
final class MethodSecurityEvaluator
{
    public const string RETURN_OBJECT = 'returnObject';

    public const string FILTER_OBJECT = 'filterObject';

    private readonly MethodSecurityRefusal $refusal;

    public function __construct(
        private readonly SecurityExpressionEvaluator $evaluator,
        private readonly RoleHierarchy $roleHierarchy,
        private readonly PermissionEvaluator $permissionEvaluator,
        private readonly ?AuthenticationEventPublisher $events = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->refusal = new MethodSecurityRefusal($evaluator, $logger);
    }

    /**
     * @param  array<int, mixed>  $args  the positional call arguments
     * @return array<string, mixed> parameter name => value, for #param references
     */
    public function bind(SecurityMethodDescriptor $rule, array $args): array
    {
        $named = [];
        foreach ($rule->params as $index => $name) {
            if (array_key_exists($index, $args)) {
                $named[$name] = $args[$index];
            }
        }

        return $named;
    }

    /**
     * @param  array<string, mixed>  $named
     * @return array<string, mixed> the named arguments as the method must receive them
     */
    public function before(SecurityMethodDescriptor $rule, array $named): array
    {
        $authentication = $this->authentication();

        if ($rule->preFilter !== null && $rule->preFilterTarget !== null && array_key_exists($rule->preFilterTarget, $named)) {
            $named[$rule->preFilterTarget] = $this->filter($rule->preFilter, $named[$rule->preFilterTarget], $authentication, $named);
        }

        if (! $this->evaluator->evaluate($rule->expression, $this->root($authentication, $named))) {
            throw $this->deny($rule, $authentication, $rule->expression, $rule->code, $rule->message);
        }

        return $named;
    }

    /**
     * @param  array<string, mixed>  $named
     */
    public function after(SecurityMethodDescriptor $rule, array $named, mixed $result): mixed
    {
        if ($rule->postExpression === null && $rule->postFilter === null) {
            return $result;
        }

        $authentication = $this->authentication();

        if ($rule->postExpression !== null && ! $this->evaluator->evaluate($rule->postExpression, $this->root($authentication, [self::RETURN_OBJECT => $result] + $named))) {
            throw $this->deny($rule, $authentication, $rule->postExpression, $rule->postCode, $rule->postMessage);
        }

        if ($rule->postFilter !== null) {
            return $this->filter($rule->postFilter, $result, $authentication, $named);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $named
     */
    private function filter(string $expression, mixed $value, Authentication $authentication, array $named): mixed
    {
        $keep = fn (mixed $element): bool => $this->evaluator->evaluate($expression, $this->root($authentication, [self::FILTER_OBJECT => $element] + $named));

        if (is_array($value)) {
            $filtered = array_filter($value, $keep);

            return array_is_list($value) ? array_values($filtered) : $filtered;
        }

        if ($value instanceof Enumerable) {
            return $value->filter($keep);
        }

        if ($value instanceof Traversable) {
            // An iterator may yield any key at all; only an array-legal one is kept, the rest are appended.
            $filtered = [];
            foreach ($value as $key => $element) {
                if (! $keep($element)) {
                    continue;
                }
                if (is_int($key) || is_string($key)) {
                    $filtered[$key] = $element;
                } else {
                    $filtered[] = $element;
                }
            }

            return $filtered;
        }

        throw new AuthorizationException('A filter rule was applied to a value that is not iterable.');
    }

    private function deny(SecurityMethodDescriptor $rule, Authentication $authentication, string $expression, ?string $code, ?string $message): SecurityException
    {
        if (! $authentication->isAuthenticated()) {
            return new AuthenticationException('Authentication is required.');
        }

        $this->events?->publishAuthorizationDenied($authentication, $rule->key(), $expression);

        return $this->refusal->refuse($rule, $authentication, $expression, $code, $message);
    }

    private function authentication(): Authentication
    {
        return SecurityContextHolder::getAuthentication() ?? Authentication::unauthenticated('anonymous', 'anonymous', null);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function root(Authentication $authentication, array $args): SecurityExpressionRoot
    {
        return new SecurityExpressionRoot($authentication, $this->roleHierarchy, $this->permissionEvaluator, $args);
    }
}
