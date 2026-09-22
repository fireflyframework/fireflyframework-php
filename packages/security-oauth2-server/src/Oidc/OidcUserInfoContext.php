<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Oidc;

use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;

/** What the userinfo mapper is handed: the authorization, its access token, the token's scopes and its claims. */
final readonly class OidcUserInfoContext
{
    /**
     * @param  list<string>  $scopes
     * @param  array<string,mixed>  $claims
     */
    public function __construct(
        public OAuth2Authorization $authorization,
        public OAuth2Token $accessToken,
        public array $scopes,
        public array $claims,
    ) {}
}
