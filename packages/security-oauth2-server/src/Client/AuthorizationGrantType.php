<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

/** The grants this server issues tokens for (OAuth 2.1: no implicit, no password). */
enum AuthorizationGrantType: string
{
    case AuthorizationCode = 'authorization_code';

    case ClientCredentials = 'client_credentials';

    case RefreshToken = 'refresh_token';
}
