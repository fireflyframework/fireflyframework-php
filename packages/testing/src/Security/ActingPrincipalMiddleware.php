<?php

declare(strict_types=1);

namespace Firefly\Testing\Security;

use Closure;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;

/**
 * Prepended to the HTTP kernel by actingAsPrincipal(), so it is the OUTERMOST middleware: it establishes the
 * acting principal before the persistence filter would look at the session (which leaves an already
 * authenticated holder alone), and every security filter and guard inside sees it. On the way out it
 * restores whatever the holder held before the request — the framework filters cleared it in their own
 * `finally`, and the test's direct calls after the request should still be acting.
 *
 * The principal is read from the container on every request rather than captured once, so a second
 * actingAsPrincipal() call swaps who the next request is, without a second copy of this middleware.
 */
final class ActingPrincipalMiddleware
{
    public function __construct(private readonly Container $app) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $previous = SecurityContextHolder::getContext();

        if ($this->app->bound(ActingPrincipal::class)) {
            /** @var ActingPrincipal $acting */
            $acting = $this->app->make(ActingPrincipal::class);
            SecurityContextHolder::setContext(new SecurityContext($acting->authentication));
        }

        try {
            return $next($request);
        } finally {
            SecurityContextHolder::setContext($previous);
        }
    }
}
