<?php

declare(strict_types=1);

namespace Firefly\Security\Session;

use Firefly\Security\Core\SecurityContext;
use Illuminate\Http\Request;

/**
 * The SecurityContext under one key of the Laravel session (Spring's HttpSessionSecurityContextRepository).
 * The context object itself is stored: Authentication, the principal (a UserDetails value object) and the
 * authorities are all immutable value objects that serialise cleanly, which is what a file, database or
 * Redis session driver does to every attribute. A request that has no session — the driver is unset, or the
 * middleware did not run — reads null and writes nothing, so the filters degrade to stateless rather than
 * throwing on `$request->session()`.
 */
final class SessionSecurityContextRepository implements SecurityContextRepository
{
    public const string KEY = 'firefly.security.context';

    public function load(Request $request): ?SecurityContext
    {
        if (! $request->hasSession()) {
            return null;
        }

        /** @var mixed $stored */
        $stored = $request->session()->get(self::KEY);

        return $stored instanceof SecurityContext && $stored->isAuthenticated() ? $stored : null;
    }

    public function save(SecurityContext $context, Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::KEY, $context);
        }
    }

    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
    }
}
