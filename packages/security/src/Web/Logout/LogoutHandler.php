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
 *   2. the session is invalidated, when `invalidate_session` and the request HAS one (Store::invalidate(): every
 *      attribute flushed and a NEW id, the old file destroyed, so the cookie the browser had names nothing from
 *      now on); with it off, the rest of the session — a shopping cart, a locale — survives,
 *   3. the stored context is removed through SecurityContextRepository, whenever `clear_authentication`,
 *      WHETHER OR NOT THERE IS A SESSION. That call is not redundant beside the invalidation and it is not
 *      session-shaped: the SHIPPED SessionSecurityContextRepository is indeed a no-op without a session, but the
 *      port exists to be rebound, and its own docblock advertises the implementations this reaches — a signed
 *      cookie, a cache keyed by a device id. A bearer-only application that bound one and never starts a session
 *      would otherwise keep its principal stored after both RP-initiated logout and POST {logout_url}: signing
 *      out would leave the thing that signs the user in untouched,
 *   4. the holder is cleared,
 *   5. LogoutSuccessEvent is published with the principal that was signed in — null for a logout of nothing,
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

        if ($this->settings->invalidateSession && $request->hasSession()) {
            $request->session()->invalidate();
        }
        if ($this->settings->clearAuthentication) {
            $this->repository->clear($request);
        }

        SecurityContextHolder::clearContext();
        $this->events->publishLogoutSuccess($authentication);
    }
}
