<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Oidc;

/**
 * The claims the userinfo endpoint answers (Spring's userInfoMapper on OidcUserInfoEndpointConfigurer). The
 * shipped DefaultOidcUserInfoMapper knows only what the principal model knows; an application that stores
 * names, pictures or addresses binds its own and reads `$context->authorization->principalName`.
 */
interface OidcUserInfoMapper
{
    /**
     * @return array<string,mixed>
     */
    public function map(OidcUserInfoContext $context): array;
}
