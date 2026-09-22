<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Logout;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Security\Web\Settings\LogoutSettings;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * POST {logout_url} (Spring's LogoutFilter). POST only, CSRF-checked against the session token, because a
 * GET that signs someone out is a link an attacker can plant on any page — a GET to the logout URL is not
 * this filter's business at all and falls through to whatever route (usually none: a 404) the application
 * has there. Ordered -93, right after the persistence filter loaded the principal — so the LogoutSuccessEvent
 * can name who left — and before the form-login filter. It answers the request itself; nothing after it in
 * the chain, and no route, ever sees a logout POST. In order:
 *
 *   1. the session CSRF token (SessionCsrf, the same check the login filter makes; a 403 on a mismatch),
 *   1b. the bound LogoutSuccessHandler, if any, is asked for the response — before anything below, so it can
 *      still read the session — and null means the default redirect of step 3,
 *   2. LogoutHandler runs the sequence that IS signing out — the remember-me cookie and every `delete_cookies`
 *      name expired on the response, the session invalidated (or only the context removed), the holder cleared,
 *      LogoutSuccessEvent published with the principal that was signed in. That sequence lives in the handler
 *      and not here because RP-initiated logout (firefly/security-oauth2-server's OidcLogoutEndpoint) ends a
 *      session too, and the two must be the same `firefly.security.logout.*` keys by construction,
 *   3. the browser goes where the bound LogoutSuccessHandler said — asked in step 1b, BEFORE the handler of
 *      step 2 ends the session, so it can still read an OIDC id token for the provider's end-session endpoint
 *      — or, when nothing is bound or it returns null, to `logout_success_url` (`/login?logout`, which the
 *      login page turns into the signed-out notice).
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
        private readonly LogoutHandler $handler,
        private readonly SessionCsrf $csrf,
        private readonly Container $container,
        private readonly Config $config,
        private readonly ?LogoutSuccessHandler $successHandler = null,
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

        // The success handler is asked FIRST, while the session is still alive: an RP-initiated logout needs the
        // id token the session holds (firefly/security-oauth2-client's OidcClientInitiatedLogoutSuccessHandler
        // reads it to build the provider's end-session URL), and null hands back to the configured redirect.
        $response = $this->successHandler?->onLogoutSuccess($request, $authentication);
        if ($response === null) {
            /** @var UrlGenerator $urls */
            $urls = $this->container->make(UrlGenerator::class);
            $response = new RedirectResponse($urls->to($this->settings->logoutSuccessUrl));
        }

        $this->handler->logout($request, $response, $authentication);

        return $response;
    }
}
