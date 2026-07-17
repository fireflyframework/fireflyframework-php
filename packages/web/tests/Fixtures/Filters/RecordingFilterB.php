<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Filters;

use Closure;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

#[Component]
#[Order(20)]
final class RecordingFilterB extends OncePerRequestFilter
{
    protected function doFilter(Request $request, Closure $next): mixed
    {
        $response = $next($request);
        if ($response instanceof Response) {
            $response->headers->set('X-Filter-Trail', (string) $response->headers->get('X-Filter-Trail', '').'B');
        }

        return $response;
    }
}
