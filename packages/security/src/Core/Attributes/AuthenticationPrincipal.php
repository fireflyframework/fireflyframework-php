<?php

declare(strict_types=1);

namespace Firefly\Security\Core\Attributes;

use Attribute;

/**
 * Binds a controller-action parameter to the current principal — Authentication::getPrincipal(): the
 * UserDetails for a form/basic/remember-me sign-in, the `sub` string for a JWT (Spring's @AuthenticationPrincipal).
 * Null when nobody is signed in, so declare the parameter `mixed` or nullable (`?string $sub`, `?UserDetails
 * $user`); a parameter that cannot take null is answered with a 401. Bound whether or not
 * `firefly.security.enabled` is on: it answers what the SecurityContextHolder holds — null with nothing
 * authenticating, the `sub` a master-independent bearer filter verified otherwise — and never the request
 * itself: an attributed parameter is the resolver's whatever its type, and a `?principal=` in the query string
 * is ignored.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AuthenticationPrincipal {}
