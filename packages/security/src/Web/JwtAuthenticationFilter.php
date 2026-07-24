<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Jwt\JwtService;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;

/**
 * Establishes the request SecurityContext from a local (HMAC) Bearer JWT and ALWAYS clears it on exit (finally),
 * so nothing bleeds into the next request even under Octane. An absent Authorization header is the anonymous
 * path — the request proceeds unauthenticated and the deny-by-default HttpSecurityFilter decides access. A
 * PRESENT-but-invalid token is fail-closed: JwtService throws a 401 that flows through the RFC-7807 renderer.
 * Ordered −90 (after the framework filters, before HttpSecurityFilter at −70). Opt-in via
 * firefly.security.jwt.enabled — both the #[ConditionalOnProperty] gate and the shouldNotFilter() guard honour it.
 */
#[Component]
#[Order(-90)]
#[ConditionalOnProperty(name: 'firefly.security.jwt.enabled', havingValue: 'true')]
final class JwtAuthenticationFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly JwtService $jwt,
        private readonly Config $config,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.jwt.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $token = $this->bearer($request);
        if ($token === null) {
            SecurityContextHolder::setContext(SecurityContext::anonymous());
        } else {
            SecurityContextHolder::setContext(new SecurityContext($this->authenticationFor($token)));
        }

        try {
            return $next($request);
        } finally {
            SecurityContextHolder::clearContext();
        }
    }

    private function bearer(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }

    private function authenticationFor(string $token): Authentication
    {
        $claims = $this->jwt->decode($token);
        $name = isset($claims['sub']) && is_scalar($claims['sub']) ? (string) $claims['sub'] : '';

        $claim = $this->config->string('firefly.security.jwt.authorities_claim', 'authorities');
        $authorities = [];
        if (isset($claims[$claim]) && is_array($claims[$claim])) {
            foreach ($claims[$claim] as $authority) {
                if (is_string($authority)) {
                    $authorities[] = new SimpleGrantedAuthority($authority);
                }
            }
        }

        return Authentication::authenticated($name, $name, $authorities, $claims);
    }
}
