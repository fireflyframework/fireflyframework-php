<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Token;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Throwable;

/**
 * An OAuth2 login could not be completed (Spring's OAuth2AuthenticationException): a 401 in the kernel's
 * taxonomy, carrying the OAuth2Error — the RFC 6749 code the provider answered, or one of this client's own
 * (`invalid_state_parameter`, `invalid_id_token`, `invalid_nonce`, …) — and the registration it happened
 * through. The login filter never lets it reach a renderer: it becomes the failure event and a redirect to
 * `failure_url`. The message names the registration and the code, and NEVER the authorization code, the PKCE
 * verifier, the client secret or a token: an exception is what ends up in a log.
 */
final class OAuth2AuthenticationException extends AuthenticationException
{
    public function __construct(
        public readonly string $registrationId,
        public readonly OAuth2Error $error,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('OAuth2 login through [%s] was refused: %s', $registrationId, $error->describe()),
            strtoupper($error->errorCode),
            $previous,
        );
    }
}
