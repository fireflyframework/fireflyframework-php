<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Web\Csrf\SessionCsrf;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CSRF protection for unsafe requests, ordered −80, with one token model per request decided by whether the
 * request has a started session.
 *
 * WITHOUT A SESSION — the stateless double-submit-cookie pattern: an unsafe request must echo the XSRF-TOKEN
 * cookie back in the X-XSRF-TOKEN header (or the _token body field), compared constant-time. Because it needs
 * no server-side session it composes with token/JWT auth.
 *
 * WITH A SESSION (session security is on, so the session middleware ran globally ahead of this filter) —
 * Laravel's session token, read through SessionCsrf from the same three sources Laravel's own
 * PreventRequestForgery reads: the `_token` field, the `X-CSRF-TOKEN` header, or the `X-XSRF-TOKEN` header
 * carrying the ENCRYPTED XSRF-TOKEN cookie a standard Laravel SPA client (Axios) echoes back. The login form,
 * the logout form and every other form agree on that one token, and the SPA client that worked against
 * Laravel's `web` group keeps working when this filter answers first. The double-submit cookie is ignored on
 * this path: a value the browser sends is not proof of anything the session did not already prove.
 *
 * Safe methods and configured path globs are exempt; any other mismatch is a 403 rendered as RFC-7807.
 */
#[Component]
#[Order(-80)]
#[ConditionalOnProperty(name: 'firefly.security.csrf.enabled', havingValue: 'true')]
final class CsrfFilter extends OncePerRequestFilter
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const COOKIE = 'XSRF-TOKEN';

    private const HEADER = 'X-XSRF-TOKEN';

    public function __construct(private readonly Config $config, private readonly SessionCsrf $sessionCsrf) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.csrf.enabled', false)) {
            return true;
        }
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return true;
        }
        $path = $request->path();
        foreach ($this->config->array('firefly.security.csrf.except', []) as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        // A request with a started session carries Laravel's session token, and that is the one token model
        // a browser session should have: the login form, the logout form and every other form agree, and
        // SessionCsrf reads it from every source Laravel's own middleware would. The double-submit cookie
        // remains the stateless path for token-authenticated clients.
        if ($request->hasSession()) {
            $this->sessionCsrf->verify($request);

            return $next($request);
        }

        $cookie = $request->cookie(self::COOKIE);
        $header = $request->header(self::HEADER) ?? $request->input('_token');

        if (! is_string($cookie) || ! is_string($header) || $cookie === '' || ! hash_equals($cookie, $header)) {
            throw new AuthorizationException('CSRF token mismatch.');
        }

        return $next($request);
    }
}
