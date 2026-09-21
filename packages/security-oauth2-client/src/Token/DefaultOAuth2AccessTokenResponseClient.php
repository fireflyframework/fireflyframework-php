<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

use Firefly\Security\OAuth2\Client\OAuth2ClientSettings;
use Firefly\Security\OAuth2\Client\Registration\ClientAuthenticationMethod;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Date;

/**
 * POST token_uri over Laravel's Http client (Spring's DefaultAuthorizationCodeTokenResponseClient,
 * DefaultRefreshTokenTokenResponseClient and DefaultClientCredentialsTokenResponseClient in one): a form body,
 * `Accept: application/json`, the client presented per its method — `client_secret_basic` as an HTTP Basic
 * header with the id and secret form-urlencoded first (RFC 6749 §2.3.1; what Spring sends), `client_secret_post`
 * in the body, `none` as the bare client_id — and the two bounded timeouts every outbound call of this package
 * has (`http.connect_timeout`, `http.timeout`).
 *
 * WHAT A FAILURE BECOMES. An error response is read for its `error`, `error_description` and `error_uri`
 * (RFC 6749 §5.2) and thrown as OAuth2AuthorizationException with that code; a response that is not a token
 * response (no access_token, a token_type that is not Bearer, not JSON, a 5xx with no error member) and a
 * transport failure are `invalid_token_response`. The messages name the registration and the code, and never
 * the form: the code, the verifier and the secret are not in any exception this class throws, nor in the
 * ConnectionException it wraps (Guzzle prints the URI, never the body).
 *
 * The Http factory is resolved from the container ON USE (an eager singleton must not depend on it at boot),
 * and it is the same instance Http::fake() stubs.
 */
final class DefaultOAuth2AccessTokenResponseClient implements OAuth2AccessTokenResponseClient
{
    public function __construct(
        private readonly Container $container,
        private readonly OAuth2ClientSettings $settings,
    ) {}

    public function authorizationCode(ClientRegistration $registration, string $code, string $redirectUri, ?string $codeVerifier): OAuth2AccessTokenResponse
    {
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri];
        if ($codeVerifier !== null) {
            $form['code_verifier'] = $codeVerifier;
        }

        return $this->request($registration, $form, $registration->scopes);
    }

    public function refreshToken(ClientRegistration $registration, OAuth2RefreshToken $refreshToken, array $scopes = []): OAuth2AccessTokenResponse
    {
        $form = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken->tokenValue];
        if ($scopes !== []) {
            $form['scope'] = implode(' ', $scopes);
        }

        return $this->request($registration, $form, $scopes);
    }

    public function clientCredentials(ClientRegistration $registration, array $scopes = []): OAuth2AccessTokenResponse
    {
        $form = ['grant_type' => 'client_credentials'];
        if ($scopes !== []) {
            $form['scope'] = implode(' ', $scopes);
        }

        return $this->request($registration, $form, $scopes);
    }

    /**
     * @param  array<string, string>  $form
     * @param  list<string>  $requestedScopes
     */
    private function request(ClientRegistration $registration, array $form, array $requestedScopes): OAuth2AccessTokenResponse
    {
        /** @var HttpFactory $http */
        $http = $this->container->make(HttpFactory::class);
        $pending = $http->asForm()
            ->acceptJson()
            ->connectTimeout($this->settings->connectTimeoutSeconds)
            ->timeout($this->settings->timeoutSeconds);

        switch ($registration->clientAuthenticationMethod) {
            case ClientAuthenticationMethod::ClientSecretBasic:
                $pending = $pending->withBasicAuth(rawurlencode($registration->clientId), rawurlencode($registration->clientSecret));
                break;
            case ClientAuthenticationMethod::ClientSecretPost:
                $form['client_id'] = $registration->clientId;
                $form['client_secret'] = $registration->clientSecret;
                break;
            case ClientAuthenticationMethod::None:
                $form['client_id'] = $registration->clientId;
                break;
        }

        try {
            $response = $pending->post($registration->providerDetails->tokenUri, $form);
        } catch (ConnectionException $e) {
            throw new OAuth2AuthorizationException($registration->registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN_RESPONSE, 'The token endpoint could not be reached.'), $e);
        }

        /** @var mixed $json */
        $json = $response->json();

        if (! $response->successful()) {
            $error = is_array($json) && is_string($json['error'] ?? null) && $json['error'] !== '' ? $json['error'] : OAuth2ErrorCodes::INVALID_TOKEN_RESPONSE;
            $description = is_array($json) && is_string($json['error_description'] ?? null) ? $json['error_description'] : "The token endpoint answered HTTP {$response->status()}.";
            $uri = is_array($json) && is_string($json['error_uri'] ?? null) ? $json['error_uri'] : null;

            throw new OAuth2AuthorizationException($registration->registrationId, new OAuth2Error($error, $description, $uri));
        }

        if (! is_array($json)) {
            throw new OAuth2AuthorizationException($registration->registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN_RESPONSE, 'The token endpoint did not answer with a JSON object.'));
        }

        /** @var array<string, mixed> $json */
        return self::parse($json, $requestedScopes, $registration->registrationId);
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  list<string>  $requestedScopes
     */
    private static function parse(array $json, array $requestedScopes, string $registrationId): OAuth2AccessTokenResponse
    {
        $accessToken = $json['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new OAuth2AuthorizationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN_RESPONSE, 'The token response carries no access_token.'));
        }
        $tokenType = $json['token_type'] ?? null;
        if (! is_string($tokenType) || strcasecmp($tokenType, 'Bearer') !== 0) {
            throw new OAuth2AuthorizationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_TOKEN_RESPONSE, 'The token response names a token_type other than Bearer.'));
        }

        $now = Date::now()->getTimestamp();

        $expiresIn = $json['expires_in'] ?? null;
        $expiresAt = match (true) {
            is_int($expiresIn) => $now + $expiresIn,
            is_string($expiresIn) && ctype_digit($expiresIn) => $now + (int) $expiresIn,
            default => null,
        };

        $scope = $json['scope'] ?? null;
        $scopes = is_string($scope) && trim($scope) !== ''
            ? array_values(array_filter(preg_split('/\s+/', trim($scope)) ?: [], static fn (string $s): bool => $s !== ''))
            : $requestedScopes;

        $refresh = $json['refresh_token'] ?? null;
        $refreshToken = is_string($refresh) && $refresh !== '' ? new OAuth2RefreshToken($refresh, $now) : null;

        $additional = $json;
        unset($additional['access_token'], $additional['token_type'], $additional['expires_in'], $additional['scope'], $additional['refresh_token']);

        return new OAuth2AccessTokenResponse(new OAuth2AccessToken($accessToken, $now, $expiresAt, $scopes), $refreshToken, $additional);
    }
}
