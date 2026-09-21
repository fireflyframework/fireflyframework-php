<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

/**
 * A successful token response (Spring's OAuth2AccessTokenResponse): the access token, the refresh token when
 * one came, and every other member — `id_token` above all — under additionalParameters.
 */
final readonly class OAuth2AccessTokenResponse
{
    /**
     * @param  array<string, mixed>  $additionalParameters
     */
    public function __construct(
        public OAuth2AccessToken $accessToken,
        public ?OAuth2RefreshToken $refreshToken = null,
        public array $additionalParameters = [],
    ) {}
}
