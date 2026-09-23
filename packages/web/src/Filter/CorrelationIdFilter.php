<?php

declare(strict_types=1);

namespace Firefly\Web\Filter;

use Closure;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads or mints X-Correlation-Id, exposes it via Context, and echoes it on the response — plus, when the
 * request carries a W3C trace id, that id on its own header (TraceContext::header(), `X-Trace-Id` by
 * default). The correlation id is never overwritten by the trace id: one id a caller matches to its own
 * request log, one id a person pastes into a trace search.
 */
final class CorrelationIdFilter extends OncePerRequestFilter
{
    public const ORDER = -100;

    public const HEADER = 'X-Correlation-Id';

    /** The Context key the id is published under, so a log line and a problem document share it. */
    public const CONTEXT_KEY = 'firefly.correlation_id';

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $correlationId = $request->header(self::HEADER) ?: (string) Str::uuid();
        Context::add(self::CONTEXT_KEY, $correlationId);
        $request->headers->set(self::HEADER, $correlationId);

        $response = $next($request);
        if ($response instanceof Response) {
            $response->headers->set(self::HEADER, $correlationId);

            // The trace id gets its OWN header rather than replacing the correlation id above: a caller that
            // sent an X-Correlation-Id is entitled to get its own value back, and the two ids answer
            // different questions. Written only when this request actually has a valid trace id, so a
            // deployment with tracing off sees no new header at all.
            $traceHeader = TraceContext::header();
            $traceId = TraceContext::traceId($request);
            if ($traceHeader !== '' && $traceId !== null) {
                $response->headers->set($traceHeader, $traceId);
            }
        }

        return $response;
    }

    /**
     * The correlation id THIS request carries, minting one if nothing did.
     *
     * The filter above runs at order -100 and publishes the id through Context, which is also what stamps it
     * on every log line of the request — so the id in a problem document and the id beside the stack trace
     * are the same string without any work by the renderer. This reads the same three places the request
     * could have been given one, in order of authority: Context (the filter ran), the request header (a
     * caller or a proxy supplied it and the filter has not run yet), and finally a fresh uuid. It never
     * returns null, because a problem without a reference is one a person cannot report and an operator
     * cannot find.
     *
     * The Context facade is consulted only when a facade application exists and the repository is bound.
     * The renderer that calls this is also used from PHP's shutdown handler after a fatal error, and from
     * tests that never booted Laravel; a facade with no root throws, and a helper that throws inside the
     * error path replaces the diagnostic with a blank page.
     */
    public static function of(Request $request): string
    {
        $context = self::context();

        $fromContext = $context?->get(self::CONTEXT_KEY);
        if (is_string($fromContext) && $fromContext !== '') {
            return $fromContext;
        }

        $fromHeader = $request->header(self::HEADER);
        if (is_string($fromHeader) && $fromHeader !== '') {
            $context?->add(self::CONTEXT_KEY, $fromHeader);

            return $fromHeader;
        }

        $minted = (string) Str::uuid();
        $context?->add(self::CONTEXT_KEY, $minted);
        $request->headers->set(self::HEADER, $minted);

        return $minted;
    }

    private static function context(): ?ContextRepository
    {
        $app = Facade::getFacadeApplication();

        if ($app === null || ! $app->bound(ContextRepository::class)) {
            return null;
        }

        /** @var ContextRepository $repository */
        $repository = $app->make(ContextRepository::class);

        return $repository;
    }
}
