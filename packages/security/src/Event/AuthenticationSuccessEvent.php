<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

use Firefly\Security\Core\Authentication;

/**
 * A principal was authenticated by any mechanism (Spring's AuthenticationSuccessEvent). Published through the
 * context ApplicationEventPublisher, so an #[AsEventListener] method taking this class receives it. Carries the
 * authenticated token only — never the credentials, which the provider erased before this fires.
 */
final readonly class AuthenticationSuccessEvent
{
    public function __construct(public Authentication $authentication) {}
}
