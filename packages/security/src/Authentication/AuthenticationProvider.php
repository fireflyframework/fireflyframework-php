<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Authentication;

/** One authentication strategy (Spring's AuthenticationProvider). */
interface AuthenticationProvider
{
    public function supports(Authentication $authentication): bool;

    /** @throws AuthenticationException */
    public function authenticate(Authentication $authentication): Authentication;
}
