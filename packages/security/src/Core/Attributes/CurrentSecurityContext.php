<?php

declare(strict_types=1);

namespace Firefly\Security\Core\Attributes;

use Attribute;

/**
 * Binds a controller-action parameter to the current SecurityContext — the anonymous one when nobody is signed
 * in (Spring's @CurrentSecurityContext). Bound whether or not `firefly.security.enabled` is on: it is whatever
 * the SecurityContextHolder holds, never something read from the request.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CurrentSecurityContext {}
