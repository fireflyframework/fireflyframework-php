<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Throwable;

/**
 * A token request the CLIENT made on its own behalf failed (Spring's OAuth2AuthorizationException): the
 * provider refused the exchange, the refresh or the client-credentials grant (the RFC 6749 code rides in
 * `error`), or could not be reached or answered something that is not a token response
 * (`invalid_token_response`). A 503 in the kernel's taxonomy — an upstream the application depends on did
 * not do its part — with `OAUTH2_` + the code as the problem code. The login provider translates it into an
 * OAuth2AuthenticationException, because for a person signing in it is a refused login. The message names
 * the registration and the code and never a secret, a code, a verifier or a token.
 */
final class OAuth2AuthorizationException extends ServiceUnavailableException
{
    public function __construct(
        public readonly string $registrationId,
        public readonly OAuth2Error $error,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('The provider of [%s] refused or failed the token request: %s', $registrationId, $error->describe()),
            'OAUTH2_'.strtoupper($error->errorCode),
            $previous,
        );
    }
}
