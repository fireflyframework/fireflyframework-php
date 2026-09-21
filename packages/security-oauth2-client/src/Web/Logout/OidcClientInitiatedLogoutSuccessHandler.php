<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web\Logout;

use Firefly\Security\Core\Authentication;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientRepository;
use Firefly\Security\OAuth2\Client\Discovery\ProviderDiscoveryException;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Registration\RedirectUriTemplate;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\Web\Logout\LogoutSuccessHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpenID Connect RP-Initiated Logout 1.0 (Spring's OidcClientInitiatedLogoutSuccessHandler): after the
 * application's own logout, the browser is sent to the provider's `end_session_endpoint` so the provider's
 * session ends too, with `id_token_hint` (the raw id token of the login — read from the encrypted authorized
 * client the session still holds, because the LogoutFilter asks its success handler BEFORE invalidating the
 * session), `client_id`, and `post_logout_redirect_uri` — `logout.post_logout_redirect_uri` with `{baseUrl}`
 * (and `{registrationId}`) expanded from the application's root, the way the redirect_uri is.
 *
 * WHICH PROVIDER: the registration id the login recorded on the token's attributes. A principal that did not
 * sign in through OAuth2 (a form login in the same application), a registration that no longer exists, a
 * provider with no end-session endpoint (explicitly configured, or a discovery document that names none), or
 * a provider whose discovery is down at that moment, all hand back to the default redirect — the person IS
 * signed out of this application either way, and the last three are logged at warning so an operator sees
 * that the provider's session outlives the application's. Nothing here reads the token's raw value into a
 * log or an exception.
 */
final class OidcClientInitiatedLogoutSuccessHandler implements LogoutSuccessHandler
{
    public function __construct(
        private readonly ClientRegistrationRepository $registrations,
        private readonly OAuth2AuthorizedClientRepository $authorizedClients,
        private readonly OAuth2ClientSettings $settings,
        private readonly Container $container,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function onLogoutSuccess(Request $request, ?Authentication $authentication): ?Response
    {
        $registrationId = OAuth2AuthenticationToken::registrationId($authentication);
        if ($registrationId === null) {
            return null;
        }

        try {
            $registration = $this->registrations->findByRegistrationId($registrationId);
        } catch (ProviderDiscoveryException $e) {
            $this->logger?->warning("RP-initiated logout skipped for [{$registrationId}]: {$e->getMessage()}", ['registration' => $registrationId, 'exception' => $e]);

            return null;
        }
        if ($registration === null) {
            $this->logger?->warning("RP-initiated logout skipped for [{$registrationId}]: the registration no longer exists.", ['registration' => $registrationId]);

            return null;
        }

        $endSession = $registration->providerDetails->endSessionUri;
        if ($endSession === null || $endSession === '') {
            $this->logger?->warning("RP-initiated logout skipped for [{$registrationId}]: its provider has no end_session_endpoint (set provider.{$registrationId}.end_session_uri, or accept that the provider's session outlives this one).", ['registration' => $registrationId]);

            return null;
        }

        /** @var UrlGenerator $urls */
        $urls = $this->container->make(UrlGenerator::class);
        $idToken = $this->authorizedClients->loadAuthorizedClient($registrationId, $request)?->idToken;

        $query = [];
        if ($idToken !== null && $idToken !== '') {
            $query['id_token_hint'] = $idToken;
        }
        $query['client_id'] = $registration->clientId;
        $query['post_logout_redirect_uri'] = RedirectUriTemplate::expand($this->settings->postLogoutRedirectUri, $urls->to('/'), $registrationId);

        return new RedirectResponse($endSession.(str_contains($endSession, '?') ? '&' : '?').http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }
}
