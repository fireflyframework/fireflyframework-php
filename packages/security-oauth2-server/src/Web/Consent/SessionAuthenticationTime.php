<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

use Illuminate\Contracts\Session\Session;

/**
 * When this session's user authenticated, for the id token's `auth_time` and the `max_age` check: the instant
 * the authorization endpoint FIRST saw the session authenticated, kept in the session. Logout invalidates the
 * session and a fresh sign-in starts a fresh count; prompt=login clears the stored principal, so the next visit
 * is anonymous, signs in again, and is stamped again.
 */
final class SessionAuthenticationTime
{
    public const string KEY = 'firefly.security.oauth2.server.auth_time';

    public static function of(Session $session): int
    {
        /** @var mixed $stored */
        $stored = $session->get(self::KEY);
        if (is_int($stored)) {
            return $stored;
        }
        $now = time();
        $session->put(self::KEY, $now);

        return $now;
    }

    public static function forget(Session $session): void
    {
        $session->forget(self::KEY);
    }
}
