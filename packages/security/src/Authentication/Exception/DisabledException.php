<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication\Exception;

use Firefly\Kernel\Exception\Security\AuthenticationException;

/** The account is disabled. A 401 via the kernel AuthenticationException base. */
final class DisabledException extends AuthenticationException {}
