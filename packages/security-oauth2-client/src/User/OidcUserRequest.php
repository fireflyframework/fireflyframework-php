<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;

/** What the OIDC user service needs (Spring's OidcUserRequest): the registration, the access token, the verified id token. */
final readonly class OidcUserRequest
{
    /**
     * @param  array<string, mixed>  $additionalParameters
     */
    public function __construct(
        public ClientRegistration $clientRegistration,
        public OAuth2AccessToken $accessToken,
        public OidcIdToken $idToken,
        public array $additionalParameters = [],
    ) {}
}
