<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Event\AsEventListener;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;

/**
 * Stamps SessionAuthenticationTime the moment a person signs in through a web mechanism, so `auth_time` and the
 * `max_age` check speak of the sign-in instant and not of the authorization endpoint's first look.
 *
 * firefly/security publishes InteractiveAuthenticationSuccessEvent from FormLoginFilter, HttpBasicFilter and
 * RememberMeAuthenticationFilter at exactly the right moment — after the session id was migrated against
 * fixation and the context stored — through the ApplicationEventPublisher port, so no filter has to know this
 * package exists. The #[AsEventListener] method is compiled into the shipped context manifest like any other and
 * registered against the dispatcher by OAuth2ServerEventListenersPass (see that pass for why the phase-800
 * sweep cannot see a package's manifest). The event carries the principal, not the request: the current request
 * is resolved from the container per call (the `request` instance the HTTP kernel rebinds for every request —
 * never captured at construction, since this is a singleton), and a request without a session (an API call
 * answered by Basic on a stateless path, a console publish) is simply not stamped: there is no session the
 * endpoint could later read.
 *
 * THE MECHANISM DECIDES WHAT IS WRITTEN. The form and HTTP Basic are active sign-ins — a credential was
 * presented — and stamp the instant. The remember-me cookie is not: nobody typed anything, and OpenID Connect
 * Core §3.1.2.1 measures `max_age` from the last ACTIVE authentication, so that mechanism marks the session as
 * remembered instead (SessionAuthenticationTime::remembered(), which keeps an active instant already there).
 * The distinction is what makes the endpoint's re-authentication demand mean something: it clears the context
 * and expires the cookie, but a browser that still sends the cookie to the login page is signed back in by
 * RememberMeAuthenticationFilter (-83, ahead of the endpoint's filter at -82) before the endpoint looks again —
 * and that sign-in must not read as the fresh one it asked for.
 *
 * Gated like every other bean of this package: the stamp is only ever read by the authorization endpoint, so a
 * session is not touched for a server that is off.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class SessionAuthenticationTimeListener
{
    public function __construct(private readonly Container $container) {}

    #[AsEventListener]
    public function onInteractiveAuthenticationSuccess(InteractiveAuthenticationSuccessEvent $event): void
    {
        $request = $this->currentRequest();
        if ($request === null || ! $request->hasSession()) {
            return;
        }

        if ($event->mechanism === InteractiveAuthenticationSuccessEvent::REMEMBER_ME) {
            SessionAuthenticationTime::remembered($request->session());

            return;
        }

        SessionAuthenticationTime::stamp($request->session());
    }

    /**
     * Request::class is Laravel's alias of the `request` instance the HTTP kernel rebinds per request; in a
     * container that holds no request (a console process, a bare container) it is not bound, and the sign-in has
     * no session to stamp.
     */
    private function currentRequest(): ?Request
    {
        if (! $this->container->bound(Request::class)) {
            return null;
        }
        try {
            return $this->container->make(Request::class);
        } catch (BindingResolutionException) {
            return null;
        }
    }
}
