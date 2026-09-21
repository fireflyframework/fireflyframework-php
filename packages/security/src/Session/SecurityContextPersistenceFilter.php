<?php

declare(strict_types=1);

namespace Firefly\Security\Session;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;

/**
 * Loads the SecurityContext from the repository into the holder at request start and clears the holder at
 * request end (Spring's SecurityContextPersistenceFilter). Ordered -94: right after the headers filter,
 * ahead of every authentication filter, so a session-held principal is what HttpSecurityFilter and the
 * method-security guards see.
 *
 * TWO DELIBERATE ASYMMETRIES. It does NOT load when the holder already carries an authenticated context —
 * a test's acting principal, or an outer middleware, established one on purpose. And on exit it saves only a
 * context that CHANGED during the request and is STILL on the holder when the exit runs; it never deletes the
 * stored one, because an inner authentication filter that clears the holder in its own `finally` does so
 * before this exit runs, and an empty holder at that point means "the request is over", not "sign out".
 *
 * WHAT THE EXIT SAVE THEREFORE STORES depends on which bearer filter is on, and the two differ. A bearer
 * principal is never stored under either — re-verified per request, by design — because the filter that
 * established it clears it on the way out. The local-JWT filter (jwt, -90) clears the holder UNCONDITIONALLY,
 * bearer or not, so while it is on a context a controller merely set on the holder is gone before this
 * filter could save it, and only a repository save reaches the session. The resource-server filter
 * (oauth2.resource_server, -85) clears only a bearer context it established itself and passes a request that
 * presented no bearer straight through, so under it — exactly as with neither bearer filter — a context a
 * controller sets on the holder on a bearer-less request IS saved by this exit. The mechanisms that
 * authenticate interactively (form, basic-with-session, remember-me) rely on none of this: they save to the
 * repository themselves at the moment of success, which is also what application code should do to sign
 * someone in programmatically (SecurityContextRepository::save()) — it behaves the same whichever bearer
 * filter is on — and logout clears it explicitly.
 *
 * The `finally` clear is the load-bearing Octane guarantee: whatever happened, nothing bleeds into the next
 * request on the same worker.
 */
#[Component]
#[Order(-94)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class SecurityContextPersistenceFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly SecurityContextRepository $repository,
        private readonly SessionSecuritySettings $settings,
        private readonly Config $config,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->settings->enabled()) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $entry = SecurityContextHolder::getContext();
        $loaded = null;

        if (! $entry->isAuthenticated()) {
            $loaded = $this->repository->load($request);
            if ($loaded !== null) {
                SecurityContextHolder::setContext($loaded);
            }
        }

        try {
            return $next($request);
        } finally {
            $current = SecurityContextHolder::getContext();
            if ($current->isAuthenticated() && $current !== $entry && $current !== $loaded) {
                $this->repository->save($current, $request);
            }
            SecurityContextHolder::clearContext();
        }
    }
}
