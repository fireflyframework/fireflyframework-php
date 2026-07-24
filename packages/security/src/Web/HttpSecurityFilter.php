<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionRoot;
use Firefly\Security\Access\HttpSecurity;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Access\UrlAuthorizationRule;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Deny-by-default URL authorization. Walks the compiled rules first-match-wins; the matched rule's expression is
 * evaluated (via the same no-eval evaluator method security uses) against the SecurityContext the auth filters
 * established. A request that matches NO rule is denied — fail-closed. A denied request is a 401 when the context
 * is anonymous (authenticate first) and a 403 when authenticated but under-privileged, surfaced through the
 * existing kernel security exceptions → ProblemDetailsRenderer (RFC-7807). Ordered −70, after every auth filter.
 */
#[Component]
#[Order(-70)]
// AND-gated: the HTTP surface flag PLUS the master flag. Repeatable #[ConditionalOnProperty] composes with AND
// semantics (ConditionEvaluator::matchesList). This filter's ctor needs SecurityExpressionEvaluator / RoleHierarchy
// / PermissionEvaluator — all master-gated #[Bean]s. Without the master condition, http.enabled=true while the
// master is off would register the filter but leave those deps unbound → BindingResolutionException 500 on every
// request. Surface flags REQUIRE the master flag.
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.http.enabled', havingValue: 'true')]
final class HttpSecurityFilter extends OncePerRequestFilter
{
    /** @var list<UrlAuthorizationRule> */
    private readonly array $rules;

    public function __construct(
        HttpSecurity $httpSecurity,
        private readonly SecurityExpressionEvaluator $evaluator,
        private readonly RoleHierarchy $roleHierarchy,
        private readonly PermissionEvaluator $permissionEvaluator,
        private readonly Config $config,
    ) {
        $this->rules = $httpSecurity->build();
    }

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.http.enabled', false)) {
            return true; // inert unless BOTH the master flag and the HTTP surface flag are on (defense-in-depth)
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $path = $request->path();
        $context = SecurityContextHolder::getContext();
        $authentication = $context->getAuthentication() ?? Authentication::unauthenticated('anonymous', 'anonymous', null);

        foreach ($this->rules as $rule) {
            if (! Str::is($rule->pattern, $path)) {
                continue;
            }
            $root = new SecurityExpressionRoot($authentication, $this->roleHierarchy, $this->permissionEvaluator, []);
            if ($this->evaluator->evaluate($rule->expression, $root)) {
                return $next($request);
            }

            throw $this->deny($context->isAuthenticated());
        }

        // No rule matched — deny-by-default.
        throw $this->deny($context->isAuthenticated());
    }

    private function deny(bool $authenticated): AuthenticationException|AuthorizationException
    {
        return $authenticated
            ? new AuthorizationException('Access is denied.')
            : new AuthenticationException('Authentication is required to access this resource.');
    }
}
