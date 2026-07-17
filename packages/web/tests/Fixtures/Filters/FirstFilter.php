<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Filters;

use Closure;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;

final class FirstFilter extends OncePerRequestFilter
{
    protected function doFilter(Request $request, Closure $next): mixed
    {
        return $next($request);
    }
}
