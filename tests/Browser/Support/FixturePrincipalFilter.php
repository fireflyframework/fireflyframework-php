<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Closure;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Illuminate\Http\Request;

/**
 * A browser has no way to authenticate against firefly/security today: there is no form login and no
 * session-persisted context (that is wave A). This middleware is the stand-in that lets the 403 scenario
 * exist — `?as=user` authenticates a ROLE_USER principal before HttpSecurityFilter evaluates its rules, and
 * clears it on the way out because the plugin serves every request from ONE long-lived process and
 * Laravel's Context is not flushed between them. Prepended to the kernel by BrowserTestCase; never a bean.
 *
 * Wave A replaces this with a real sign-in and the scenarios move to it.
 */
final class FixturePrincipalFilter
{
    public const string QUERY = 'as';

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->query(self::QUERY) !== 'user') {
            return $next($request);
        }

        SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated(
            'browser-user',
            'browser-user',
            [new SimpleGrantedAuthority('ROLE_USER')],
        )));

        try {
            return $next($request);
        } finally {
            SecurityContextHolder::clearContext();
        }
    }
}
