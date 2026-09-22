<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web;

use Illuminate\Http\Request;

/**
 * The authorization request under one key of the Laravel session (Spring's
 * HttpSessionOAuth2AuthorizationRequestRepository, in its one-request-per-session form): a second login started
 * in the same session replaces the first, which is what a person who clicked twice expects. A request with no
 * session reads null and writes nothing, so a misconfigured boot degrades to a refused callback rather than a
 * throw on `$request->session()` — the redirect filter refuses to start a login without a session anyway.
 */
final class SessionAuthorizationRequestRepository implements AuthorizationRequestRepository
{
    public const string KEY = 'firefly.security.oauth2.client.authorization_request';

    public function loadAuthorizationRequest(Request $request): ?OAuth2AuthorizationRequest
    {
        if (! $request->hasSession()) {
            return null;
        }

        /** @var mixed $stored */
        $stored = $request->session()->get(self::KEY);

        return $stored instanceof OAuth2AuthorizationRequest ? $stored : null;
    }

    public function saveAuthorizationRequest(OAuth2AuthorizationRequest $authorizationRequest, Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(self::KEY, $authorizationRequest);
        }
    }

    public function removeAuthorizationRequest(Request $request): ?OAuth2AuthorizationRequest
    {
        if (! $request->hasSession()) {
            return null;
        }

        /** @var mixed $stored */
        $stored = $request->session()->pull(self::KEY);

        return $stored instanceof OAuth2AuthorizationRequest ? $stored : null;
    }
}
