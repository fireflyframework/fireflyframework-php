<?php

declare(strict_types=1);

namespace Firefly\Security\Core\Attributes;

use Attribute;

/** Binds a controller-action parameter to the current SecurityContext — the anonymous one when nobody is signed in (Spring's @CurrentSecurityContext). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentSecurityContext {}
