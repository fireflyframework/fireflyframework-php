<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

use Illuminate\Contracts\Session\Session;

/**
 * When this session's user authenticated, for the id token's `auth_time` and the `max_age` check (OpenID Connect
 * Core §2 and §3.1.2.1): one unix instant kept in the session under KEY.
 *
 * It is WRITTEN by SessionAuthenticationTimeListener the moment firefly/security reports an interactive sign-in
 * (InteractiveAuthenticationSuccessEvent — the form, HTTP Basic, a remember-me cookie), which the filters publish
 * after the session id was migrated and the context stored, so the stamp is the sign-in instant and not the
 * instant the authorization endpoint first happened to look. A browser that signed in hours ago and then arrives
 * with `max_age=60` is therefore sent to sign in again, and the id token it eventually gets says when it did.
 *
 * of() still stamps `now` when it finds nothing — ONLY as the fallback for a session that authenticated before
 * the listener could see it: one signed in before the server was enabled, or through a mechanism that publishes
 * no interactive event (a test's actingAsPrincipal(), an application filter of its own). Such a session is
 * treated as authenticated the first time the endpoint looks, which is the most it can say about it; from then
 * on the stamp is kept, and the fresh sign-in the endpoint demands for prompt=login or an exceeded `max_age`
 * (forget(), then the entry point) is stamped by the listener like any other. Logout invalidates the session, so
 * a fresh sign-in starts a fresh count.
 */
final class SessionAuthenticationTime
{
    public const string KEY = 'firefly.security.oauth2.server.auth_time';

    /** The sign-in instant, written at sign-in — see SessionAuthenticationTimeListener. */
    public static function stamp(Session $session): void
    {
        $session->put(self::KEY, time());
    }

    /** The stored instant; `now`, stamped, only for a session the listener never saw (see the class docblock). */
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
