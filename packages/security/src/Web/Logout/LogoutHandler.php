<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Logout;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Web\RememberMe\RememberMeServices;
use Firefly\Security\Web\Settings\LogoutSettings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * WHAT SIGNING OUT MEANS, as ONE bean (Spring's list of LogoutHandlers — SecurityContextLogoutHandler,
 * CookieClearingLogoutHandler and RememberMeServices::logout — collapsed into the single collaborator LaraFly's
 * one filter chain needs). Every path that ends a session calls this and nothing else, so `firefly.security.logout.*`
 * governs all of them BY CONSTRUCTION: `LogoutFilter` for the framework's `POST {logout_url}`, and
 * `OidcLogoutEndpoint` for OpenID Connect RP-initiated logout at `{oidc_logout_endpoint}`. A second path that
 * re-implemented the sequence inline would silently ignore whichever keys it forgot, which is exactly what one
 * of them did before this bean existed.
 *
 * In order, on the response the caller is about to return:
 *
 *   1. the remember-me cookie — when the port is bound — and every `delete_cookies` name are expired: an empty
 *      value, an expiry in the past, on the root path,
 *   2. the session is invalidated (Store::invalidate(): every attribute flushed and a NEW id, the old file
 *      destroyed, so the cookie the browser had names nothing from now on) or, with `invalidate_session` off and
 *      `clear_authentication` on, only the stored context is removed and the rest of the session — a shopping
 *      cart, a locale — survives. A request that carries no session at all is left alone: there is nothing to
 *      invalidate and SecurityContextRepository::clear() is a no-op without one,
 *   3. the holder is cleared,
 *   4. LogoutSuccessEvent is published with the principal that was signed in — null for a logout of nothing,
 *      which is still answered: signing out when nobody is signed in is not an error.
 *
 * `LogoutSettings::enabled` is NOT consulted here. That flag decides whether the framework MAPS its own logout
 * URL (it follows `form_login.enabled`), not what signing out does — an application that turned the form logout
 * off and drives RP-initiated logout instead still gets the sequence its `logout.*` keys describe.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class LogoutHandler
{
    public function __construct(
        private readonly LogoutSettings $settings,
        private readonly SecurityContextRepository $repository,
        private readonly AuthenticationEventPublisher $events,
        private readonly ?RememberMeServices $rememberMe = null,
    ) {}

    /**
     * @param  Authentication|null  $authentication  who was signed in, read by the caller BEFORE the holder is
     *                                               cleared, so the event can name them
     */
    public function logout(Request $request, Response $response, ?Authentication $authentication): void
    {
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
    }
}
