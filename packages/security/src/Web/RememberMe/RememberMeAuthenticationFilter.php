<?php

declare(strict_types=1);

namespace Firefly\Security\Web\RememberMe;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Session\SessionSecuritySettings;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;

/**
 * Re-authenticates a request whose session holds no principal from the remember-me cookie (Spring's
 * RememberMeAuthenticationFilter). Ordered -83: after every filter that could have established a principal
 * some other way (the session at -94, form/basic/JWT/OAuth2 in between), before CSRF and URL authorization.
 * A success is an interactive sign-in: the session id is regenerated (fixation protection, the same rule the
 * form-login filter applies), the context is stored through the SecurityContextRepository, both success
 * events fire with the REMEMBER_ME mechanism — so the cookie is consulted once per session, not once per
 * request: the next request carries the session and the persistence filter loads the principal before this
 * filter ever looks at the cookie. A bad cookie is simply the anonymous path: RememberMeServices answers
 * null for a missing, malformed, expired, mis-signed, unknown, disabled or locked token, and this filter
 * asks nothing more.
 *
 * INERT unless `firefly.security.remember_me.enabled`: the component exists under the master flag like every
 * other authentication filter, but the RememberMeServices bean is bound only under the remember-me flag, so
 * the port is nullable and its absence — or the flag being off — is a pass-through. The holder is cleared
 * in `finally` like every other authentication filter: the repository save is what carries the sign-in to
 * the next request, and nothing may bleed into the next request on the same worker.
 */
#[Component]
#[Order(-83)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class RememberMeAuthenticationFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly SecurityContextRepository $repository,
        private readonly SessionSecuritySettings $session,
        private readonly AuthenticationEventPublisher $events,
        private readonly Config $config,
        private readonly ?RememberMeServices $services = null,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if ($this->services === null || ! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.remember_me.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        if (SecurityContextHolder::getContext()->isAuthenticated() || $this->services === null) {
            return $next($request);
        }

        $authentication = $this->services->autoLogin($request);
        if ($authentication === null) {
            return $next($request);
        }

        if ($request->hasSession() && $this->session->fixationProtection()) {
            $request->session()->migrate(true);
        }

        $context = new SecurityContext($authentication);
        SecurityContextHolder::setContext($context);
        $this->repository->save($context, $request);
        $this->events->publishInteractiveSuccess($authentication, InteractiveAuthenticationSuccessEvent::REMEMBER_ME);

        try {
            return $next($request);
        } finally {
            SecurityContextHolder::clearContext();
        }
    }
}
