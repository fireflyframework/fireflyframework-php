<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/**
 * The provider's endpoints (Spring's ClientRegistration.ProviderDetails): configured explicitly, taken from a
 * CommonOAuth2Provider preset, or discovered from `issuer_uri` — by the time a ClientRegistration exists, the
 * two the flows cannot do without (authorization, token) are always known. `userNameAttribute` names the claim
 * or userinfo attribute that becomes the principal's name (`sub` for OIDC, `id` for GitHub).
 */
final readonly class ProviderDetails
{
    public function __construct(
        public string $authorizationUri,
        public string $tokenUri,
        public ?string $jwkSetUri = null,
        public ?string $userInfoUri = null,
        public string $userNameAttribute = 'sub',
        public ?string $issuerUri = null,
        public ?string $endSessionUri = null,
    ) {}
}
