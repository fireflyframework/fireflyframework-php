<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Settings;

/**
 * How an access token is represented (Spring's OAuth2TokenFormat): a self-contained JWT any resource server
 * verifies against the JWKS, or an opaque reference only this server can resolve (through introspection or
 * the userinfo endpoint).
 */
enum OAuth2TokenFormat: string
{
    case SelfContained = 'self_contained';

    case Reference = 'reference';
}
