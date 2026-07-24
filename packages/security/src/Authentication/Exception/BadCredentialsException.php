<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication\Exception;

use Firefly\Kernel\Exception\Security\AuthenticationException;

/** Password mismatch. A 401 via the kernel AuthenticationException base. */
final class BadCredentialsException extends AuthenticationException {}
