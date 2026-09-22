<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Error;

use Firefly\Web\Exception\InvalidRequestException;

/**
 * An authorization request the server MUST NOT redirect (RFC 6749 §4.1.2.1): a missing or unknown `client_id`,
 * or a `redirect_uri` the client did not register. Redirecting would send the browser wherever the attacker
 * asked, so the resource owner is shown the error instead — firefly/web renders this 400 as its HTML page for a
 * browser and as problem+json for a client that asked for JSON.
 */
final class InvalidAuthorizationRequestException extends InvalidRequestException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 'INVALID_AUTHORIZATION_REQUEST');
    }
}
