<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\Secured;

use Firefly\Security\Access\Attributes\Secured;

/**
 * An authority value containing an apostrophe generates a malformed hasAnyAuthority('o'brien-team') expression
 * once quoted — proving the scanner must validate GENERATED expressions too, not just raw #[PreAuthorize] text.
 * Kept in its own namespace/directory for the same isolation reason as BrokenPreAuthorizeController.
 */
final class ApostropheSecuredController
{
    #[Secured("o'brien-team")]
    public function show(): void {}
}
