<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;

/**
 * The token endpoint, one method per grant (Spring's OAuth2AccessTokenResponseClient family collapsed into
 * one port, because the three requests share everything but the form). Every method answers a parsed
 * OAuth2AccessTokenResponse or throws OAuth2AuthorizationException.
 */
interface OAuth2AccessTokenResponseClient
{
    public function authorizationCode(ClientRegistration $registration, string $code, string $redirectUri, ?string $codeVerifier): OAuth2AccessTokenResponse;

    /**
     * @param  list<string>  $scopes
     */
    public function refreshToken(ClientRegistration $registration, OAuth2RefreshToken $refreshToken, array $scopes = []): OAuth2AccessTokenResponse;

    /**
     * @param  list<string>  $scopes
     */
    public function clientCredentials(ClientRegistration $registration, array $scopes = []): OAuth2AccessTokenResponse;
}
