<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication\Exception;

use Firefly\Kernel\Exception\Security\AuthenticationException;

/** Unknown username. A 401 (via the kernel AuthenticationException base) — deliberately indistinguishable to
 *  the client from a bad password (see BadCredentialsException) to avoid user enumeration. */
final class UsernameNotFoundException extends AuthenticationException {}
