<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

use Firefly\Security\Core\Authentication;

/** A sign-out completed (Spring's LogoutSuccessEvent). The token is null when nobody was signed in. */
final readonly class LogoutSuccessEvent
{
    public function __construct(public ?Authentication $authentication) {}
}
