<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\InteractiveAuthenticationSuccessEvent;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientService;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Client\Web\Login\OAuth2LoginAuthentication;
use Firefly\Security\OAuth2\Client\Web\Login\OAuth2LoginAuthenticationProvider;
use Firefly\Security\Session\SavedRequest;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Session\SessionSecuritySettings;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET {redirection_endpoint_base_uri}/{registrationId} — where the provider sends the browser back (Spring's
 * OAuth2LoginAuthenticationFilter). It answers the request itself; nothing after it in the chain, and no
 * route, ever sees a callback. Ordered -88: after the session persistence filter (the authorization request
 * and the resulting principal both live in the session) and before HttpSecurityFilter, so no URL rule is
 * needed for the callback path. In order:
 *
 *   1. THE AUTHORIZATION REQUEST IS PULLED FROM THE SESSION FIRST, before anything is compared — so a state is
 *      single-use whatever the callback turns out to be (a replayed callback finds nothing), it is bound to
 *      the session it was minted in (it lives nowhere else), and its comparison is constant-time (hash_equals);
 *   2. an `error` in the query is the provider's refusal, reported under the RFC 6749 code it sent;
 *   3. no saved request, or one started for another registration → `authorization_request_not_found`; a
 *      missing or different `state` → `invalid_state_parameter`; a callback URL that is not the exact
 *      `redirect_uri` the request was made with → `invalid_redirect_uri` (a code delivered somewhere else
 *      is not exchanged); no `code` → `invalid_request`;
 *   4. OAuth2LoginAuthenticationProvider: the exchange, the id token, userinfo, the principal, the mapper;
 *   5. on success: the session id is regenerated (fixation protection — the attributes and the saved request
 *      survive, exactly as the form-login filter does it), the context is stored through the
 *      SecurityContextRepository (which stores the principal WITHOUT the raw id token), the authorized client
 *      is stored in the session repository (encrypted; the RP-initiated logout and the manager read it there)
 *      and in the cache service (encrypted; a job started later can act as this person), the two success
 *      events fire, and the browser goes to the saved request or `login.default_success_url`;
 *   6. on any OAuth2AuthenticationException: the failure event (an empty username — the provider never told
 *      us who — and the source ip) and a redirect to `login.failure_url`, which the login page turns into the
 *      provider sentence. A 503 — discovery down, the JWKS unreachable — is NOT a refused login and propagates:
 *      the token was never examined, and the person should see that the provider is unavailable, not that
 *      their sign-in was wrong.
 *
 * The holder is cleared in `finally`: nothing bleeds into the next request on the same worker. The redirect
 * targets and the callback's own URL go through Laravel's UrlGenerator, resolved ON USE (the FormLoginFilter
 * reasoning): current() is built from the same root to('/') expanded the redirect_uri from, so behind a
 * TLS-terminating proxy with URL::forceScheme() the two compare equal, and the failure/success redirects are
 * formatted exactly as the application's own.
 *
 * TRIPLE-GATED, re-read live in shouldNotFilter(): the security master flag (this filter signs into the
 * master-gated session repository and publishes through the master-gated publisher), the package master, and
 * `login.enabled`.
 */
#[Component]
#[Order(-88)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.client.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.client.login.enabled', havingValue: 'true')]
final class OAuth2LoginAuthenticationFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly ClientRegistrationRepository $registrations,
        private readonly AuthorizationRequestRepository $requests,
        private readonly OAuth2LoginAuthenticationProvider $provider,
        private readonly OAuth2AuthorizedClientRepository $authorizedClients,
        private readonly OAuth2AuthorizedClientService $authorizedClientService,
        private readonly SecurityContextRepository $repository,
        private readonly SessionSecuritySettings $session,
        private readonly AuthenticationEventPublisher $events,
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
        $registrationId = $this->settings->registrationIdOfRedirection($request);
        if ($registrationId === null) {
            return $next($request);
        }

        $registration = $this->registrations->findByRegistrationId($registrationId);
        if ($registration === null || $registration->authorizationGrantType !== AuthorizationGrantType::AuthorizationCode) {
            return $next($request);
        }

        try {
            $login = $this->authenticate($registration, $request);
        } catch (OAuth2AuthenticationException $e) {
            $this->events->publishAuthenticationFailure($e, '', (string) $request->ip());

            return $this->redirect($this->settings->failureUrl);
        }

        try {
            if ($request->hasSession() && $this->session->fixationProtection()) {
                $request->session()->migrate(true);
            }

            $context = new SecurityContext($login->authentication);
            SecurityContextHolder::setContext($context);
            $this->repository->save($context, $request);
            $this->authorizedClients->saveAuthorizedClient($login->authorizedClient, $request);
            $this->authorizedClientService->saveAuthorizedClient($login->authorizedClient);
            $this->events->publishInteractiveSuccess($login->authentication, InteractiveAuthenticationSuccessEvent::OAUTH2_LOGIN);

            return $this->redirect($this->successUrl($request));
        } finally {
            SecurityContextHolder::clearContext();
        }
    }

    /** Steps 1–4 of the class docblock: every refusal is an OAuth2AuthenticationException naming the registration and the code. */
    private function authenticate(ClientRegistration $registration, Request $request): OAuth2LoginAuthentication
    {
        $id = $registration->registrationId;
        $authorizationRequest = $this->requests->removeAuthorizationRequest($request); // single use, whatever follows

        $error = $this->query($request, 'error');
        if ($error !== null) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error($error, $this->query($request, 'error_description') ?? '', $this->query($request, 'error_uri')));
        }

        if ($authorizationRequest === null) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::AUTHORIZATION_REQUEST_NOT_FOUND, 'No authorization request is waiting in this session.'));
        }
        if ($authorizationRequest->registrationId !== $id) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::AUTHORIZATION_REQUEST_NOT_FOUND, 'The authorization request waiting in this session was started for another registration.'));
        }

        $state = $this->query($request, 'state');
        if ($state === null || ! hash_equals($authorizationRequest->state, $state)) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::INVALID_STATE_PARAMETER, 'The state does not match the authorization request.'));
        }

        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);
        if ($urls->current() !== $authorizationRequest->redirectUri) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::INVALID_REDIRECT_URI, 'The callback did not arrive at the redirect_uri the authorization request named.'));
        }

        $code = $this->query($request, 'code');
        if ($code === null) {
            throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'The callback carries neither a code nor an error.'));
        }

        return $this->provider->authenticate($registration, $authorizationRequest, $code);
    }

    /** A query parameter as the non-empty string it is, else null — an array or an empty value is "absent", never a type error. */
    private function query(Request $request, string $name): ?string
    {
        /** @var mixed $value */
        $value = $request->query($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function successUrl(Request $request): string
    {
        if (! $this->settings->alwaysUseDefaultSuccessUrl && $request->hasSession()) {
            $saved = SavedRequest::consume($request->session());
            if ($saved !== null) {
                return $saved;
            }
        }

        return $this->settings->defaultSuccessUrl;
    }

    /** A 302 to a configured path or an absolute URL, formatted exactly as the application's own redirects are. */
    private function redirect(string $url): RedirectResponse
    {
        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);

        return new RedirectResponse($urls->to($url));
    }
}
