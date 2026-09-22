<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Error;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Throwable;

/**
 * The one exception every endpoint of the server throws for a protocol refusal (Spring's
 * OAuth2AuthenticationException): it carries the OAuth2Error the client must be told and the HTTP status the
 * endpoint answers with — 400 for most, 401 for `invalid_client` and a bad bearer, 403 for `insufficient_scope`,
 * 429 for the rate limiter. It extends the kernel's AuthenticationException so one that escapes an endpoint
 * still renders as a 401 problem through firefly/web rather than as a 500; the endpoints catch it themselves and
 * render the RFC document or the redirect. The message names the code and the sentence, NEVER a secret, a code, a
 * verifier or a token value — every description in this package is written under that rule.
 */
final class OAuth2AuthenticationException extends AuthenticationException
{
    public function __construct(
        private readonly OAuth2Error $error,
        private readonly int $status = 400,
        ?Throwable $previous = null,
    ) {
        $message = $error->description === '' ? $error->errorCode : "{$error->errorCode}: {$error->description}";

        parent::__construct($message, 'OAUTH2_'.strtoupper($error->errorCode), $previous);
    }

    public function error(): OAuth2Error
    {
        return $this->error;
    }

    public function status(): int
    {
        return $this->status;
    }
}
