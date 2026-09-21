<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/** The grant a registration is used with (Spring's AuthorizationGrantType). RefreshToken names the exchange the manager makes, never a registration. */
enum AuthorizationGrantType: string
{
    case AuthorizationCode = 'authorization_code';
    case ClientCredentials = 'client_credentials';
    case RefreshToken = 'refresh_token';
}
