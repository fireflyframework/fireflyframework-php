<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

use Illuminate\Contracts\Session\Session;

/**
 * When this session's user ACTIVELY authenticated, for the id token's `auth_time` and the `max_age` check (OpenID
 * Connect Core §2 and §3.1.2.1): one value kept in the session under KEY — a unix instant, or REMEMBERED.
 *
 * It is WRITTEN by SessionAuthenticationTimeListener the moment firefly/security reports an interactive sign-in
 * (InteractiveAuthenticationSuccessEvent), which the filters publish after the session id was migrated and the
 * context stored, so the stamp is the sign-in instant and not the instant the authorization endpoint first
 * happened to look. A browser that signed in hours ago and then arrives with `max_age=60` is therefore sent to
 * sign in again, and the id token it eventually gets says when it did.
 *
 * NOT EVERY INTERACTIVE SIGN-IN IS AN ACTIVE ONE. The form and HTTP Basic carry a credential the person just
 * presented: stamp() records the instant. The remember-me cookie carries no credential — the person is signed in
 * because a browser sent a cookie it was handed at some earlier sign-in — and §3.1.2.1 asks `max_age` to be
 * measured from the last time the user was "actively authenticated", which the cookie is not (Spring draws the
 * same line between IS_AUTHENTICATED_FULLY and IS_AUTHENTICATED_REMEMBERED). remembered() therefore writes
 * REMEMBERED in place of an instant, unless an instant is already there (an active sign-in earlier in the same
 * session is still the last active one), and of() answers null for it: the endpoint treats any `max_age` as
 * exceeded — a fresh, credentialed sign-in, with the cookie expired on the way — and issues an id token without
 * `auth_time` when no `max_age` was asked, since the instant is simply not known. Without this, a cookie that
 * signs the browser back in on the very request after the endpoint demanded a re-authentication would satisfy
 * `prompt=login` and `max_age` with no credential entered at all.
 *
 * of() still stamps `now` when it finds NOTHING — only as the fallback for a session that authenticated before
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

    /** The value under KEY for a session the remember-me cookie signed in: a principal, but no active instant. */
    public const string REMEMBERED = 'remembered';

    /** The active sign-in instant, written at sign-in — see SessionAuthenticationTimeListener. */
    public static function stamp(Session $session): void
    {
        $session->put(self::KEY, time());
    }

    /**
     * The session was signed in by the remember-me cookie: REMEMBERED, unless an active instant from earlier in
     * this session is there — that one stays the last active authentication (see the class docblock).
     */
    public static function remembered(Session $session): void
    {
        if (! is_int($session->get(self::KEY))) {
            $session->put(self::KEY, self::REMEMBERED);
        }
    }

    /**
     * The stored instant; null for a session the cookie signed in (REMEMBERED); `now`, stamped, only for a session
     * the listener never saw (see the class docblock).
     */
    public static function of(Session $session): ?int
    {
        /** @var mixed $stored */
        $stored = $session->get(self::KEY);
        if (is_int($stored)) {
            return $stored;
        }
        if ($stored === self::REMEMBERED) {
            return null;
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
