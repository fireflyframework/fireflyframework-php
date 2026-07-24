<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication;

use Firefly\Security\Authentication\Exception\BadCredentialsException;
use Firefly\Security\Authentication\Exception\DisabledException;
use Firefly\Security\Authentication\Exception\LockedException;
use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Password\PasswordEncoder;
use Firefly\Security\User\UserDetailsService;

/**
 * Username/password authentication against a UserDetailsService (Spring's DaoAuthenticationProvider). To defeat
 * username enumeration, an unknown username is NOT distinguishable from a wrong password by either response time
 * or response content: on user-not-found we run a real verify against a constructor-precomputed dummy hash (so the
 * wall-clock matches a genuine credential check for ANY PasswordEncoder) and then throw the SAME generic
 * BadCredentialsException as a bad password. On success it returns a NEW authenticated token with the credentials
 * erased — the raw password never survives past this method. Account-status failures (locked/disabled) are only
 * surfaced AFTER a correct password (OWASP-recommended order): an attacker without the password learns nothing
 * about account existence or state.
 */
final class DaoAuthenticationProvider implements AuthenticationProvider
{
    /** A fixed placeholder whose encoded form is verified on the not-found path to equalize timing. */
    private const DUMMY_PASSWORD = 'firefly-dummy-password-for-timing-mitigation';

    private readonly string $dummyHash;

    public function __construct(
        private readonly UserDetailsService $users,
        private readonly PasswordEncoder $encoder,
    ) {
        // Precompute with the REAL encoder (same algorithm/cost) so the user-not-found verify below is
        // timing-equivalent to a genuine credential check across any PasswordEncoder (bcrypt/argon2id/delegating).
        $this->dummyHash = $encoder->encode(self::DUMMY_PASSWORD);
    }

    public function supports(Authentication $authentication): bool
    {
        return ! $authentication->isAuthenticated() && is_string($authentication->getCredentials());
    }

    public function authenticate(Authentication $authentication): Authentication
    {
        $raw = $authentication->getCredentials();
        $presented = is_string($raw) ? $raw : '';

        try {
            $user = $this->users->loadUserByUsername($authentication->getName());
        } catch (UsernameNotFoundException) {
            // Enumeration mitigation: a real verify against the dummy hash (equal timing) + the SAME generic 401
            // (identical message/body) as a bad password. Unknown user === wrong password to any observer.
            $this->encoder->matches($presented, $this->dummyHash);

            throw new BadCredentialsException('Bad credentials.');
        }

        if (! $this->encoder->matches($presented, $user->getPassword())) {
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
