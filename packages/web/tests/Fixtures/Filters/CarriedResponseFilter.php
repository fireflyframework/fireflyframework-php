<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Filters;

use Closure;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Stops one path with a response it has already built, the way a middleware that has decided the request is
 * over does it — `throw new HttpResponseException($response)`.
 *
 * IT IS A FILTER AND NOT A CONTROLLER ON PURPOSE. Illuminate\Routing\Route::run() CATCHES
 * HttpResponseException and returns its response, so one thrown from inside a controller never reaches the
 * exception handler and could not show whether the renderable claims it. Thrown from the filter chain —
 * which runs above the router, in the foundation kernel's pipeline — it takes the ordinary route to
 * Handler::render(), where the renderable is consulted BEFORE the `match (true)` arm that would return the
 * carried response. Claiming it there throws away a response the application had already built and answers
 * 500 instead.
 *
 * Ordered outermost so nothing else has to know it exists, and inert on every other path, so the filter
 * trail the capstone asserts is untouched.
 */
#[Component]
#[Order(1)]
final class CarriedResponseFilter extends OncePerRequestFilter
{
    public const string PATH = 'boom/carried-response';

    public const string BODY = 'a response the application already built';

    protected function doFilter(Request $request, Closure $next): mixed
    {
        if ($request->path() === self::PATH) {
            throw new HttpResponseException(new Response(self::BODY, 418));
        }

        return $next($request);
    }
}
