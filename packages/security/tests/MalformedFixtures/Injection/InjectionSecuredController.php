<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\Injection;

use Firefly\Security\Access\Attributes\RolesAllowed;

/**
 * Unlike ApostropheSecuredController (an apostrophe that ACCIDENTALLY breaks tokenization), this value is a
 * deliberate, grammar-VALID injection: once spliced into hasAnyRole('...'), the result is
 * `hasAnyRole('X') or permitAll() or hasAnyRole('Y')` — a syntactically legal expression that would parse and
 * evaluate successfully (always granting access via permitAll()), so the scanner must reject the raw VALUE
 * (any embedded quote) before it ever reaches the parser, not rely on the parser to catch it. Kept in its own
 * namespace/directory for the same isolation reason as BrokenPreAuthorizeController/ApostropheSecuredController.
 */
final class InjectionSecuredController
{
    #[RolesAllowed("X') or permitAll() or hasAnyRole('Y")]
    public function show(): void {}
}
