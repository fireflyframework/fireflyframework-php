<?php

declare(strict_types=1);

namespace Firefly\Testing\Security;

use Firefly\Security\Core\Authentication;

/** The principal a test is acting as, bound in the container for ActingPrincipalMiddleware to read per request. */
final readonly class ActingPrincipal
{
    public function __construct(public Authentication $authentication) {}
}
