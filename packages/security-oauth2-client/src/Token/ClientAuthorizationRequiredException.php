<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

use Firefly\Kernel\Exception\Security\AuthenticationException;

/**
 * The manager was asked for a user-bound client that does not exist or cannot be refreshed (Spring's
 * ClientAuthorizationRequiredException): the person has to sign in through the registration (again). A 401,
 * so the entry point sends a browser to do exactly that.
 */
final class ClientAuthorizationRequiredException extends AuthenticationException
{
    public function __construct(public readonly string $registrationId)
    {
        parent::__construct(
            sprintf('An authorized client for [%s] is required: sign in through that registration first.', $registrationId),
            'CLIENT_AUTHORIZATION_REQUIRED',
        );
    }
}
