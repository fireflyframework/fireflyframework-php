<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2;

use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Exception\Security\InvalidTokenException;
use Firefly\Kernel\Exception\Security\TokenExpiredException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Throwable;

/**
 * Validates a Bearer JWT against the issuer's JWKS (via the injected JwksProvider — no live network on the hot
 * path) and maps standard claims to authorities: each `scope`/`scp` entry becomes `SCOPE_<value>` and each
 * configured roles-claim entry is carried through verbatim. COMPOSES with the local-JWT filter rather than
 * replacing it: if the context is already authenticated (the −90 filter ran first), this filter no-ops. Ordered
 * −85. Fail-closed: a present-but-invalid token, or one missing `exp`, is a 401; an absent header is anonymous.
 */
#[Component]
#[Order(-85)]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.resource_server.enabled', havingValue: 'true')]
final class OAuth2ResourceServerFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly JwksProvider $jwks,
        private readonly Config $config,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.oauth2.resource_server.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        if (SecurityContextHolder::getContext()->isAuthenticated()) {
            return $next($request);
        }

        $token = $this->bearer($request);
        if ($token === null) {
            return $next($request);
        }

        SecurityContextHolder::setContext(new SecurityContext($this->authenticationFor($token)));

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
        try {
            $decoded = JWT::decode($token, $this->jwks->keys());
        } catch (ExpiredException $e) {
            throw new TokenExpiredException('Resource-server JWT has expired.', 'TOKEN_EXPIRED', $e);
        } catch (Throwable $e) {
            throw new InvalidTokenException('Resource-server JWT could not be validated against the JWKS.', 'INVALID_TOKEN', $e);
        }

        /** @var array<string,mixed> $claims */
        $claims = (array) $decoded;
        if (! array_key_exists('exp', $claims)) {
            throw new InvalidTokenException('Resource-server JWT is missing the mandatory exp claim.');
        }

        $name = isset($claims['sub']) && is_scalar($claims['sub']) ? (string) $claims['sub'] : '';

        return Authentication::authenticated($name, $name, $this->authorities($claims), $claims);
    }

    /**
     * @param  array<string,mixed>  $claims
     * @return list<SimpleGrantedAuthority>
     */
    private function authorities(array $claims): array
    {
        $authorities = [];

        $scopeClaim = $claims['scope'] ?? $claims['scp'] ?? null;
        $scopes = is_string($scopeClaim) ? preg_split('/\s+/', trim($scopeClaim)) : (is_array($scopeClaim) ? $scopeClaim : []);
        foreach ($scopes ?: [] as $scope) {
            if (is_string($scope) && $scope !== '') {
                $authorities[] = new SimpleGrantedAuthority('SCOPE_'.$scope);
            }
        }

        $rolesClaim = $this->config->string('firefly.security.oauth2.resource_server.authorities_claim', 'roles');
        if (isset($claims[$rolesClaim]) && is_array($claims[$rolesClaim])) {
            foreach ($claims[$rolesClaim] as $role) {
                if (is_string($role)) {
                    $authorities[] = new SimpleGrantedAuthority($role);
                }
            }
        }

        return $authorities;
    }
}
