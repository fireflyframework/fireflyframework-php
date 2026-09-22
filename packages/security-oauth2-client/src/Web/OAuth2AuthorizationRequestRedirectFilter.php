<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET {authorization_endpoint_base_uri}/{registrationId} (Spring's OAuth2AuthorizationRequestRedirectFilter):
 * resolves the authorization request for the named registration, keeps it in the session, and answers the
 * 302 to the provider. It answers the request itself — nothing after it in the chain, and no route, sees it.
 * A path that names no registration, a registration that is not an authorization-code one, or anything but
 * a GET is not this filter's business and falls through — to the URL rules and, past them, the router: an
 * application whose rules leave the prefix open gets the router's 404, one whose `*` rule needs a principal
 * gets the entry point's refusal, and neither ever gets a redirect to a provider. Ordered -89: after the
 * session persistence filter (the session is what the request is kept in) and before HttpSecurityFilter, so
 * no URL rule is needed for the start URL — Spring places it before its authorization filter for the same
 * reason.
 *
 * THE BASE URL COMES FROM LARAVEL'S UrlGenerator, resolved ON USE (the FormLoginFilter reasoning: it is built
 * from the bound request, and this filter is an eager singleton constructed at boots that bind none): `to('/')`
 * honours URL::forceScheme()/forceRootUrl(), so behind a TLS-terminating proxy the provider is told to come
 * back to the https address the browser is on, not the http one PHP saw.
 *
 * A LOGIN WITHOUT A SESSION IS REFUSED, not started: the state would have nowhere to live and the callback
 * would always be `authorization_request_not_found`. `login.enabled` implies the session (SessionSecuritySettings),
 * so this only fires when the session middleware was removed by hand — a ConfigurationException names it.
 *
 * TRIPLE-GATED: the security master flag (the login filter's beans), the package master and `login.enabled`,
 * all re-read live in shouldNotFilter().
 */
#[Component]
#[Order(-89)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
final class OAuth2AuthorizationRequestRedirectFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly ClientRegistrationRepository $registrations,
        private readonly OAuth2AuthorizationRequestResolver $resolver,
        private readonly AuthorizationRequestRepository $requests,
        private readonly OAuth2ClientSettings $settings,
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false)
            || ! $this->config->bool('firefly.security.oauth2.client.enabled', false)
            || ! $this->config->bool('firefly.security.oauth2.client.login.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $registrationId = $this->settings->registrationIdOfAuthorizationRequest($request);
        if ($registrationId === null) {
            return $next($request);
        }

        $registration = $this->registrations->findByRegistrationId($registrationId);
        if ($registration === null || $registration->authorizationGrantType !== AuthorizationGrantType::AuthorizationCode) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            throw new ConfigurationException("OAuth2 login through [{$registrationId}] cannot start: the request has no session to keep the authorization request in. firefly.security.oauth2.client.login.enabled implies the session middleware (SessionSecurityBootstrap); check that session.driver is set and that nothing removed StartSession from the global stack.");
        }

        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);
        $authorizationRequest = $this->resolver->resolve($registration, $urls->to('/'));
        $this->requests->saveAuthorizationRequest($authorizationRequest, $request);

        return new RedirectResponse($authorizationRequest->toUri());
    }
}
