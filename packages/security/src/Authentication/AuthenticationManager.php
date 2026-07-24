<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Authentication;

/** The façade the auth filters call (Spring's AuthenticationManager). */
interface AuthenticationManager
{
    /** @throws AuthenticationException */
    public function authenticate(Authentication $authentication): Authentication;
}
