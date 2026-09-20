<?php

declare(strict_types=1);

namespace Firefly\Security\Session;

use Firefly\Security\Core\SecurityContext;
use Illuminate\Http\Request;

/**
 * Where a SecurityContext lives BETWEEN requests (Spring's SecurityContextRepository). The shipped
 * implementation is the Laravel session; an application may bind its own (a signed cookie, a cache keyed by
 * a device id) and every filter that persists a principal goes through it.
 */
interface SecurityContextRepository
{
    /** The stored context, or null when there is none or it is anonymous. */
    public function load(Request $request): ?SecurityContext;

    public function save(SecurityContext $context, Request $request): void;

    public function clear(Request $request): void;
}
