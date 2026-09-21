<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/**
 * How the client proves itself at the token endpoint (RFC 6749 §2.3.1; Spring's ClientAuthenticationMethod):
 * the id and secret as an HTTP Basic header (the default when a secret is configured), both in the form body,
 * or nothing — a public client, which then ALWAYS uses PKCE.
 */
enum ClientAuthenticationMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';
    case ClientSecretPost = 'client_secret_post';
    case None = 'none';
}
