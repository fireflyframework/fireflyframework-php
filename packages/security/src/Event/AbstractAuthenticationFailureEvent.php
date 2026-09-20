<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

use Firefly\Kernel\Exception\Security\AuthenticationException;

/**
 * An authentication attempt was refused (Spring's AbstractAuthenticationFailureEvent). It names the USERNAME
 * that was presented and the SOURCE IP — the two facts a lockout policy, an audit log or a metric needs — and
 * the exception, and deliberately nothing else: the presented password never reaches an event object, because
 * an event is exactly the kind of thing that ends up serialised into a log.
 */
abstract readonly class AbstractAuthenticationFailureEvent
{
    public function __construct(
        public string $username,
        public string $ip,
        public AuthenticationException $exception,
    ) {}
}
