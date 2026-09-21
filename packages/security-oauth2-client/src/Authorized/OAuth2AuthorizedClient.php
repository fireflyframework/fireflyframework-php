<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Authorized;

use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Security\OAuth2\Client\Token\OAuth2RefreshToken;

/**
 * A registration a principal has authorized, with its tokens (Spring's OAuth2AuthorizedClient). It carries the
 * registration ID and never the registration: this object is what the session repository and the cache service
 * store (encrypted), and a client secret must not travel with it. `idToken` is the raw id token of an OIDC login
 * — the one thing the RP-initiated logout needs and the principal in the session no longer has — and null for a
 * client-credentials client or a plain OAuth2 login.
 */
final readonly class OAuth2AuthorizedClient
{
    public function __construct(
        public string $registrationId,
        public string $principalName,
        public OAuth2AccessToken $accessToken,
        public ?OAuth2RefreshToken $refreshToken = null,
        public ?string $idToken = null,
    ) {}

    public function getClientRegistrationId(): string
    {
        return $this->registrationId;
    }

    public function getPrincipalName(): string
    {
        return $this->principalName;
    }

    public function getAccessToken(): OAuth2AccessToken
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?OAuth2RefreshToken
    {
        return $this->refreshToken;
    }

    public function getIdToken(): ?string
    {
        return $this->idToken;
    }
}
