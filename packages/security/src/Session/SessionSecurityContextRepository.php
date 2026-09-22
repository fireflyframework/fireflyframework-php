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
 *
 * NO CREDENTIAL IS WRITTEN. save() stores Authentication::eraseCredentials() of what it is given, never the
 * token itself: the credentials slot is already null on an authenticated token, and a principal that is a
 * CredentialsContainer — the shipped User, whose getPassword() is the encoded hash the DaoAuthenticationProvider
 * verified against — is replaced by its credential-free copy, so the hash never reaches
 * storage/framework/sessions, the sessions table, Redis, or a debug page that dumps the session. The context
 * handed in is left untouched (it is the token the remember-me services sign their cookie with), and load()
 * hands back the stored copy, whose principal reports an empty password. An application UserDetails that
 * wants the same guarantee implements CredentialsContainer; one that does not is stored as it is.
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
        if (! $request->hasSession()) {
            return;
        }

        $authentication = $context->getAuthentication();

        $request->session()->put(
            self::KEY,
            $authentication === null ? $context : new SecurityContext($authentication->eraseCredentials()),
        );
    }

    public function clear(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::KEY);
        }
    }
}
