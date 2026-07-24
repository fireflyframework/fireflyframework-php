<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication;

use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Authentication\Exception\DisabledException;
use Firefly\Security\Authentication\Exception\LockedException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Password\PasswordEncoder;
use Firefly\Security\User\UserDetailsService;

/**
 * Username/password authentication against a UserDetailsService (Spring's DaoAuthenticationProvider). Loads the
 * user (an unknown username surfaces as UsernameNotFoundException — a 401 indistinguishable from a bad password
 * to the client, preventing enumeration), constant-time-verifies via the PasswordEncoder, then checks the
 * account flags, mapping each failure to its 401 subtype. On success it returns a NEW authenticated token with
 * the credentials erased — the raw password never survives past this method.
 */
final class DaoAuthenticationProvider implements AuthenticationProvider
{
    public function __construct(
        private readonly UserDetailsService $users,
        private readonly PasswordEncoder $encoder,
    ) {}

    public function supports(Authentication $authentication): bool
    {
        return ! $authentication->isAuthenticated() && is_string($authentication->getCredentials());
    }

    public function authenticate(Authentication $authentication): Authentication
    {
        $user = $this->users->loadUserByUsername($authentication->getName());

        $raw = $authentication->getCredentials();
        if (! is_string($raw) || ! $this->encoder->matches($raw, $user->getPassword())) {
            throw new BadCredentialsException('Bad credentials.');
        }
        if (! $user->isAccountNonLocked()) {
            throw new LockedException('Account is locked.');
        }
        if (! $user->isEnabled()) {
            throw new DisabledException('Account is disabled.');
        }

        return Authentication::authenticated($user->getUsername(), $user, $user->getAuthorities());
    }
}
