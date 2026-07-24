<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Stateless CSRF protection via the double-submit-cookie pattern: an unsafe request must echo the XSRF-TOKEN
 * cookie back in the X-XSRF-TOKEN header (or the _token body field), compared constant-time. Because it needs no
 * server-side session it composes with token/JWT auth. Safe methods and configured path globs are exempt; any
 * other mismatch is a 403 rendered as RFC-7807. Ordered −80.
 */
#[Component]
#[Order(-80)]
#[ConditionalOnProperty(name: 'firefly.security.csrf.enabled', havingValue: 'true')]
final class CsrfFilter extends OncePerRequestFilter
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    private const COOKIE = 'XSRF-TOKEN';

    private const HEADER = 'X-XSRF-TOKEN';

    public function __construct(private readonly Config $config) {}

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
        $cookie = $request->cookie(self::COOKIE);
        $header = $request->header(self::HEADER) ?? $request->input('_token');

        if (! is_string($cookie) || ! is_string($header) || $cookie === '' || ! hash_equals($cookie, $header)) {
            throw new AuthorizationException('CSRF token mismatch.');
        }

        return $next($request);
    }
}
