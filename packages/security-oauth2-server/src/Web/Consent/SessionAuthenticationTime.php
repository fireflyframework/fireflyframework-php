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
 * ofSessionHeldPrincipal() still stamps `now` when it finds NOTHING — only as the fallback for a session that
 * authenticated before the listener could see it: one signed in before the server was enabled, or through a
 * mechanism that publishes no interactive event (an application filter of its own that stores its context
 * through the SecurityContextRepository). Such a session is treated as authenticated the first time the endpoint
 * looks, which is the most it can say about it; from then on the stamp is kept, and the fresh sign-in the
 * endpoint demands for prompt=login or an exceeded `max_age` (forget(), then the entry point) is stamped by the
 * listener like any other. Logout invalidates the session, so a fresh sign-in starts a fresh count.
 *
 * THAT FALLBACK IS WHY THE READER NAMES ITS PRECONDITION. Stamping `now` says "this session authenticated at
 * least as recently as this moment", which is true of a session that carries the principal and false of a
 * request that merely arrived with one — a bearer the resource-server filter authenticated, for instance, whose
 * every request opens a cookie-less session of its own: stamped on the way past, any `max_age` would be
 * satisfied and the id token would claim an `auth_time` nobody ever authenticated at. The endpoint therefore
 * proves the principal is the one the SecurityContextRepository holds (AuthorizationEndpoint::sessionHeldPrincipal())
 * before it asks, and the reader is named for that proof: a caller that cannot make it must not call this.
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
     * The stored instant for a session whose principal is SESSION-HELD — the caller has proven it, see the class
     * docblock: null for a session the cookie signed in (REMEMBERED); `now`, stamped, only for a session the
     * listener never saw.
     */
    public static function ofSessionHeldPrincipal(Session $session): ?int
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
