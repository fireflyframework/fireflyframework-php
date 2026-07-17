<?php

declare(strict_types=1);

namespace Firefly\Web\Filter;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Reads or mints X-Correlation-Id, exposes it via Context, and echoes it on the response. */
final class CorrelationIdFilter extends OncePerRequestFilter
{
    public const ORDER = -100;

    public const HEADER = 'X-Correlation-Id';

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $correlationId = $request->header(self::HEADER) ?: (string) Str::uuid();
        Context::add('firefly.correlation_id', $correlationId);
        $request->headers->set(self::HEADER, $correlationId);

        $response = $next($request);
        if ($response instanceof Response) {
            $response->headers->set(self::HEADER, $correlationId);
        }

        return $response;
    }
}
