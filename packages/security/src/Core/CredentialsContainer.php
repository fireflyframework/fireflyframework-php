<?php

declare(strict_types=1);

namespace Firefly\Security\Core;

/**
 * A value that carries a credential and can hand back a copy of itself without it (Spring's
 * CredentialsContainer). Spring's is a mutator; Firefly's principals are immutable value objects, so this one
 * DERIVES — the shape Authentication::eraseCredentials() already has. The shipped User implements it (its
 * getPassword() is the encoded hash the DaoAuthenticationProvider verified against), Authentication asks a
 * principal that does for its credential-free copy when its own credentials are erased, and that copy is what
 * SessionSecurityContextRepository writes: a file session, a sessions table, a Redis store or a page that
 * dumps the session never holds an encoded password. An application UserDetails that carries a hash
 * implements this too; one that does not is stored as it is.
 */
interface CredentialsContainer
{
    /** The same value with its credential gone; the receiver is left untouched. */
    public function eraseCredentials(): static;
}
