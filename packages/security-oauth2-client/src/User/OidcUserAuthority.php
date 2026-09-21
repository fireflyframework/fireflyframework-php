<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Oidc\OidcUserInfo;

/**
 * `OIDC_USER` (Spring's OidcUserAuthority): the OAuth2 authority that also carries the id token's claims and
 * the userinfo. The id token it holds is ALWAYS the value-less copy — an authority is stored in the session
 * with the Authentication, and a raw token must not travel there.
 */
final readonly class OidcUserAuthority extends OAuth2UserAuthority
{
    public const string OIDC_USER = 'OIDC_USER';

    public function __construct(
        private OidcIdToken $idToken,
        private ?OidcUserInfo $userInfo = null,
        string $authority = self::OIDC_USER,
    ) {
        parent::__construct($authority, [...$idToken->getClaims(), ...($userInfo?->getClaims() ?? [])]);
    }

    public function getIdToken(): OidcIdToken
    {
        return $this->idToken;
    }

    public function getUserInfo(): ?OidcUserInfo
    {
        return $this->userInfo;
    }
}
