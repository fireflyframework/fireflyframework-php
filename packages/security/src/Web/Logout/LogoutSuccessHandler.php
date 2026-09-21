<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Logout;

use Firefly\Security\Core\Authentication;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What answers a successful logout POST (Spring's LogoutSuccessHandler). LogoutFilter asks the bound handler
 * FIRST, before the remember-me cookie is expired and BEFORE the session is invalidated — so the handler can
 * still read what the session holds (an OIDC id token to hand the provider, a locale to keep) — and falls back
 * to the redirect to `logout_success_url` when it answers null. Spring's handler writes the response itself;
 * Firefly's returns one, because nothing here writes to an output stream. One implementation is bound at a
 * time; firefly/security-oauth2-client's OIDC RP-initiated logout is the first.
 */
interface LogoutSuccessHandler
{
    public function onLogoutSuccess(Request $request, ?Authentication $authentication): ?Response;
}
