<?php

declare(strict_types=1);

namespace Firefly\Security\Web\RememberMe;

use Firefly\Security\Core\Authentication;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The remember-me port (Spring's RememberMeServices): the form-login filter calls loginSuccess() on a
 * successful sign-in so the cookie can be set, the remember-me filter calls autoLogin() when the session holds
 * no principal, and the logout filter calls logout() to expire it. Bound only when
 * `firefly.security.remember_me.enabled`; the filters that take it accept null.
 */
interface RememberMeServices
{
    public function autoLogin(Request $request): ?Authentication;

    public function loginSuccess(Request $request, Response $response, Authentication $authentication): void;

    public function logout(Request $request, Response $response): void;
}
