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
