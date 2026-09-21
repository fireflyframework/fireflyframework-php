<?php

declare(strict_types=1);

namespace Firefly\Security\Core\Attributes;

use Attribute;

/**
 * Binds a controller-action parameter to the current principal — Authentication::getPrincipal(): the
 * UserDetails for a form/basic/remember-me sign-in, the `sub` string for a JWT (Spring's @AuthenticationPrincipal).
 * The principal is handed over only when it is what the parameter declares — `?UserDetails $user` receives a
 * UserDetails and is null for a JWT's `sub`, `?string $sub` receives the `sub` and is null for a UserDetails,
 * `mixed $principal` receives either — so the same action can serve a form login and a bearer token without
 * a TypeError (Spring answers the mismatch with null too; its errorOnInvalidType is off by default). Null as
 * well when nobody is signed in, so declare the parameter `mixed` or nullable; a parameter that cannot take
 * null is answered with a 401 in both cases. Bound whether or not `firefly.security.enabled` is on: it answers
 * what the SecurityContextHolder holds — null with nothing authenticating, the `sub` a master-independent bearer
 * filter verified otherwise — and never the request itself: an attributed parameter is the resolver's whatever
 * its type, and a `?principal=` in the query string is ignored.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AuthenticationPrincipal {}
