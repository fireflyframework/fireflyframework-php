<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;

/** What a user service needs to load a plain OAuth2 user (Spring's OAuth2UserRequest). */
final readonly class OAuth2UserRequest
{
    /**
     * @param  array<string, mixed>  $additionalParameters
     */
    public function __construct(
        public ClientRegistration $clientRegistration,
        public OAuth2AccessToken $accessToken,
        public array $additionalParameters = [],
    ) {}
}
