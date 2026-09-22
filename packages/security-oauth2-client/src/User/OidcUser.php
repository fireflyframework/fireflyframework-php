<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\OAuth2\Client\Oidc\ClaimAccessor;
use Firefly\Security\OAuth2\Client\Oidc\OidcIdToken;
use Firefly\Security\OAuth2\Client\Oidc\OidcUserInfo;

/**
 * A principal signed in through OpenID Connect (Spring's OidcUser): an OAuth2User whose attributes ARE the
 * claims — the id token's, with the userinfo's on top — plus the id token and the userinfo themselves. This is
 * the type to declare in a controller: `#[AuthenticationPrincipal] OidcUser $user`.
 */
interface OidcUser extends ClaimAccessor, OAuth2User
{
    public function getIdToken(): OidcIdToken;

    public function getUserInfo(): ?OidcUserInfo;

    public function getSubject(): string;

    public function getEmail(): ?string;

    public function getFullName(): ?string;

    public function getPreferredUsername(): ?string;
}
