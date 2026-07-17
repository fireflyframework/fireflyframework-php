<?php

declare(strict_types=1);

namespace Firefly\Web\Filter;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

/** Seeds a readonly request-scoped id into Laravel's Context for the duration of the request. */
final class RequestContextFilter extends OncePerRequestFilter
{
    public const ORDER = -200;

    protected function doFilter(Request $request, Closure $next): mixed
    {
        Context::add('firefly.request_id', (string) Str::uuid());

        return $next($request);
    }
}
