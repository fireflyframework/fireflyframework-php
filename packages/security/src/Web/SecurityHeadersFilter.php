<?php

declare(strict_types=1);

namespace Firefly\Security\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps conservative security headers on every response — HSTS, X-Frame-Options (DENY), X-Content-Type-Options
 * (nosniff), Referrer-Policy, and a default-src 'self' Content-Security-Policy — every value overridable via
 * firefly.security.headers.*. Ordered −95 so it wraps the whole chain and the headers survive on error responses
 * too. Only mutates a Symfony Response (skips streamed/other returns defensively).
 */
#[Component]
#[Order(-95)]
#[ConditionalOnProperty(name: 'firefly.security.headers.enabled', havingValue: 'true')]
final class SecurityHeadersFilter extends OncePerRequestFilter
{
    public function __construct(private readonly Config $config) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.headers.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            return $response;
        }

        $response->headers->set('Strict-Transport-Security', $this->config->string('firefly.security.headers.hsts', 'max-age=31536000; includeSubDomains'));
        $response->headers->set('X-Frame-Options', $this->config->string('firefly.security.headers.frame_options', 'DENY'));
        $response->headers->set('X-Content-Type-Options', $this->config->string('firefly.security.headers.content_type_options', 'nosniff'));
        $response->headers->set('Referrer-Policy', $this->config->string('firefly.security.headers.referrer_policy', 'no-referrer'));
        $response->headers->set('Content-Security-Policy', $this->config->string('firefly.security.headers.csp', "default-src 'self'"));

        return $response;
    }
}
