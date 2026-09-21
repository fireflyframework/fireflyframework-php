<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Logout;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Security\Web\RememberMe\RememberMeServices;
use Firefly\Security\Web\Settings\LogoutSettings;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * POST {logout_url} (Spring's LogoutFilter). POST only, CSRF-checked against the session token, because a
 * GET that signs someone out is a link an attacker can plant on any page — a GET to the logout URL is not
 * this filter's business at all and falls through to whatever route (usually none: a 404) the application
 * has there. Ordered -93, right after the persistence filter loaded the principal — so the LogoutSuccessEvent
 * can name who left — and before the form-login filter. It answers the request itself; nothing after it in
 * the chain, and no route, ever sees a logout POST. In order:
 *
 *   1. the session CSRF token (SessionCsrf, the same check the login filter makes; a 403 on a mismatch),
 *   2. the remember-me cookie — when the port is bound — and every `delete_cookies` name are expired on the
 *      response: an empty value, an expiry in the past, on the root path,
 *   3. the session is invalidated (Store::invalidate(): every attribute flushed and a NEW id, the old file
 *      destroyed, so the cookie the browser had names nothing from now on) or, with `invalidate_session`
 *      off and `clear_authentication` on, only the stored context is removed and the rest of the session —
 *      a shopping cart, a locale — survives,
 *   4. the holder is cleared, the event fires with the principal that was signed in (null for a logout POST
 *      from an anonymous session, which is still answered: signing out of nothing is not an error),
 *   5. the browser goes to `logout_success_url` (`/login?logout`, which the login page turns into the
 *      signed-out notice).
 *
 * THE REDIRECT GOES THROUGH LARAVEL'S UrlGenerator, resolved from the container ON USE, for the reasons the
 * form-login filter gives: `to()` is what the application's own redirects build and what a test's
 * assertRedirect() compares against, it honours URL::forceScheme()/forceRootUrl() behind a TLS-terminating
 * proxy, and the generator cannot be a constructor dependency of an eager singleton that is built at boots
 * which never bind a request.
 */
#[Component]
#[Order(-93)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class LogoutFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly LogoutSettings $settings,
        private readonly SecurityContextRepository $repository,
        private readonly AuthenticationEventPublisher $events,
        private readonly SessionCsrf $csrf,
        private readonly Container $container,
        private readonly Config $config,
        private readonly ?RememberMeServices $rememberMe = null,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->settings->enabled) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        if (! $this->settings->isLogout($request)) {
            return $next($request);
        }

        $this->csrf->verify($request);

        $authentication = SecurityContextHolder::getAuthentication();

        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);
        $response = new RedirectResponse($urls->to($this->settings->logoutSuccessUrl));

        $this->rememberMe?->logout($request, $response);
        foreach ($this->settings->deleteCookies as $name) {
            $response->headers->setCookie(new Cookie($name, '', 1, '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
        }

        if ($request->hasSession()) {
            if ($this->settings->invalidateSession) {
                $request->session()->invalidate();
            } elseif ($this->settings->clearAuthentication) {
                $this->repository->clear($request);
            }
        }

        SecurityContextHolder::clearContext();
        $this->events->publishLogoutSuccess($authentication);

        return $response;
    }
}
