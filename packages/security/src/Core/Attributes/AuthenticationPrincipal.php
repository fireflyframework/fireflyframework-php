<?php

declare(strict_types=1);

namespace Firefly\Security\Core\Attributes;

use Attribute;

/**
 * Binds a controller-action parameter to the current principal — Authentication::getPrincipal(): the
 * UserDetails for a form/basic/remember-me sign-in, the `sub` string for a JWT (Spring's @AuthenticationPrincipal).
 * Null when nobody is signed in; declare the parameter `mixed` or nullable.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AuthenticationPrincipal {}
