<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\AuthorizationGrantType;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistrationRepository;
use Firefly\Security\OAuth2\Client\Token\ClientAuthorizationRequiredException;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessTokenResponseClient;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthorizationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Spring's DefaultOAuth2AuthorizedClientManager and AuthorizedClientServiceOAuth2AuthorizedClientManager in one,
 * because the difference between them is only where the client is looked up:
 *
 *   client_credentials   the application's own token. Looked up in the cache service under the registration and
 *                        the principal name given (else the client_id — "the application"), fetched with the
 *                        client's credentials and the registration's scopes when absent or within `clock_skew`
 *                        seconds of expiring, and cached: one token per process pool, not one per request.
 *   authorization_code   a person's token. Looked up in the SESSION first when the current request has one
 *                        (the login stored it there; the request is what binds it to the browser), else in the
 *                        cache service under the principal name given (else the signed-in name) — the path a
 *                        queued job takes. Absent → ClientAuthorizationRequiredException (401: sign in through
 *                        the registration). Live → returned. Within `clock_skew` of expiry → refreshed with the
 *                        refresh token (the rotated refresh token and the id token are kept, the result is
 *                        written back to the session and to the service); no refresh token → the dead entry
 *                        is removed and ClientAuthorizationRequiredException; a refresh the provider answers
 *                        `invalid_grant` to (revoked, rotated elsewhere) → the dead entry is removed and the
 *                        OAuth2AuthorizationException propagates, so the caller sees the provider's answer
 *                        once and ClientAuthorizationRequiredException from then on (Spring's behaviour).
 *
 * The current request is read from the container ON USE ('request' is bound per request, never at boot), and
 * a request without a session — an API call under a bearer, a console command — simply has no session entry.
 * Time is read through the Date facade, as the token client and the cache service read it, so a test that
 * travels moves the manager's clock with theirs.
 */
final class DefaultOAuth2AuthorizedClientManager implements OAuth2AuthorizedClientManager
{
    public function __construct(
        private readonly ClientRegistrationRepository $registrations,
        private readonly OAuth2AccessTokenResponseClient $tokens,
        private readonly OAuth2AuthorizedClientService $service,
        private readonly OAuth2ClientSettings $settings,
        private readonly Container $container,
        private readonly ?OAuth2AuthorizedClientRepository $sessionClients = null,
    ) {}

    public function authorize(string $clientRegistrationId, ?string $principalName = null): OAuth2AuthorizedClient
    {
        $registration = $this->registrations->findByRegistrationId($clientRegistrationId)
            ?? throw new ConfigurationException("There is no client registration [{$clientRegistrationId}] under firefly.security.oauth2.client.registration to authorize.");
        $now = Date::now()->getTimestamp();

        if ($registration->authorizationGrantType === AuthorizationGrantType::ClientCredentials) {
            return $this->clientCredentials($registration, $principalName ?? $registration->clientId, $now);
        }

        return $this->userBound($registration, $principalName, $now);
    }

    private function clientCredentials(ClientRegistration $registration, string $principalName, int $now): OAuth2AuthorizedClient
    {
        $id = $registration->registrationId;
        $client = $this->service->loadAuthorizedClient($id, $principalName);
        if ($client !== null && ! $client->accessToken->isExpired($now, $this->settings->clockSkewSeconds)) {
            return $client;
        }

        $response = $this->tokens->clientCredentials($registration, $registration->scopes);
        $client = new OAuth2AuthorizedClient($id, $principalName, $response->accessToken, $response->refreshToken);
        $this->service->saveAuthorizedClient($client);

        return $client;
    }

    private function userBound(ClientRegistration $registration, ?string $principalName, int $now): OAuth2AuthorizedClient
    {
        $id = $registration->registrationId;
        $request = $this->currentRequest();
        $principalName ??= SecurityContextHolder::getAuthentication()?->getName();

        $client = $request !== null ? $this->sessionClients?->loadAuthorizedClient($id, $request) : null;
        if ($client === null && $principalName !== null) {
            $client = $this->service->loadAuthorizedClient($id, $principalName);
        }
        if ($client === null) {
            throw new ClientAuthorizationRequiredException($id);
        }
        if (! $client->accessToken->isExpired($now, $this->settings->clockSkewSeconds)) {
            return $client;
        }

        $refreshToken = $client->refreshToken;
        if ($refreshToken === null) {
            $this->forget($client, $request);

            throw new ClientAuthorizationRequiredException($id);
        }

        try {
            $response = $this->tokens->refreshToken($registration, $refreshToken);
        } catch (OAuth2AuthorizationException $e) {
            if ($e->error->errorCode === OAuth2ErrorCodes::INVALID_GRANT) {
                $this->forget($client, $request);
            }

            throw $e;
        }

        $refreshed = new OAuth2AuthorizedClient($id, $client->principalName, $response->accessToken, $response->refreshToken ?? $refreshToken, $client->idToken);
        if ($request !== null) {
            $this->sessionClients?->saveAuthorizedClient($refreshed, $request);
        }
        $this->service->saveAuthorizedClient($refreshed);

        return $refreshed;
    }

    private function forget(OAuth2AuthorizedClient $client, ?Request $request): void
    {
        if ($request !== null) {
            $this->sessionClients?->removeAuthorizedClient($client->registrationId, $request);
        }
        $this->service->removeAuthorizedClient($client->registrationId, $client->principalName);
    }

    /** The bound request when it has a session; null at boot, in a console command, or under a bearer without one. */
    private function currentRequest(): ?Request
    {
        if (! $this->container->bound('request')) {
            return null;
        }

        /** @var mixed $request */
        $request = $this->container->make('request');

        return $request instanceof Request && $request->hasSession() ? $request : null;
    }
}
