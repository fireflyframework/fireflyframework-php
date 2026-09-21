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
 * stored one, because the inner authentication filters (jwt at -90, oauth2.resource_server at -85) clear the
 * holder in their own `finally` before this exit runs, and an empty holder at that point means "the request is
 * over", not "sign out". That same clearing is why the exit save is narrower than it looks: a bearer
 * principal is never stored — re-verified per request, by design — and once either bearer filter is on, a
 * context a controller merely set on the holder is gone by the time this filter could save it, so the save
 * branch only ever fires when no inner filter cleared the holder first (a controller sign-in with neither
 * bearer filter enabled). The mechanisms that authenticate interactively (form, basic-with-session,
 * remember-me) do not rely on it: they save to the repository themselves at the moment of success, which is
 * also what application code should do to sign someone in programmatically (SecurityContextRepository::save()),
 * and logout clears it explicitly.
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
