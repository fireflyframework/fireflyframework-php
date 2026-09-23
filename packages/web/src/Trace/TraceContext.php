<?php

declare(strict_types=1);

namespace Firefly\Web\Trace;

use Firefly\Config\Config;
use Firefly\Web\Filter\CorrelationIdFilter;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;

/**
 * THE ID A PERSON QUOTES, and where it comes from.
 *
 * Before this class, problem+json's `traceId` was the correlation id — a uuid this package minted — while
 * the W3C trace id lived on the HTTP-exchange row and in the logs. A member literally named `traceId` held
 * a value no trace backend has ever heard of, so a person who screenshotted a 500 page and pasted its
 * reference into a trace search got nothing, every time, and the document was not lying about anything a
 * tool could check. This class makes the name true: when tracing is on and the current span is valid,
 * `traceId`, the HTML page's Reference row and the echoed trace header all publish the W3C trace id; when it
 * is not, every one of them falls back to the correlation id, which is exactly what they carried before.
 *
 * THE CORRELATION ID IS NOT ABSORBED. It keeps its own header (`X-Correlation-Id`, never overwritten — a
 * caller that sent one is entitled to get its own id back) and gains its own member in the problem document
 * (`correlationId`). One id a person pastes into a trace search, one id a caller matches to its own request
 * log, related by appearing side by side and conflated nowhere. This is deliberately NOT `traceresponse`:
 * the W3C response header is a separate, unshipped thing and this header is the de-facto `X-Trace-Id` a
 * gateway or a browser agent already reads.
 *
 * WHY THIS LIVES IN firefly/web AND READS TWO STRING KEYS. Deptrac does not permit Web -> Observability
 * (the dependency runs the other way: TracingFilter is an Observability filter that extends a Web one), so
 * this package cannot ask a Tracer anything. It does not need to. TracingFilter already publishes the
 * request's ids twice — to Laravel's Context and to the Request's attribute bag — precisely so a consumer
 * that has only a Request can read them, and those two key names are owned HERE from now on; TracingFilter's
 * own constants are aliases of these, so there is one string and no chance of drift. A request that no
 * tracing filter touched simply has neither, and referenceFor() answers the correlation id.
 *
 * VALIDATED, NOT TRUSTED. The id is checked against the W3C rule (32 lowercase hex digits, not the reserved
 * all-zero value) before it is published — the same rule SpanContext::isValid() applies, restated here
 * because this package cannot import that class. A NoOp tracer's invalid context therefore never reaches a
 * document, and neither does a value some other middleware happened to leave under the same key.
 *
 * EVERY LOOKUP IS FACADE-SAFE. Like CorrelationIdFilter::of(), this is called from the shutdown handler
 * after a fatal error and from tests that never booted Laravel, so a facade with no root must not throw: a
 * helper that throws inside the error path replaces the diagnostic with a blank page.
 */
final class TraceContext
{
    /** The Context/attribute key the request's W3C trace id is published under. */
    public const string TRACE_ID = 'firefly.trace_id';

    /** The same, for the current span id. */
    public const string SPAN_ID = 'firefly.span_id';

    public const string ENABLED_KEY = 'firefly.web.trace-id.enabled';

    public const string HEADER_KEY = 'firefly.web.trace-id.header';

    public const string DEFAULT_HEADER = 'X-Trace-Id';

    private const string INVALID_TRACE_ID = '00000000000000000000000000000000';

    /**
     * The W3C trace id THIS request carries, or null.
     *
     * The attribute bag is read first because it is the copy a bare Request carries into a unit test and
     * into the shutdown handler, and Context second because a job, a console command or a span started
     * outside the HTTP filter publishes only there.
     */
    public static function traceId(Request $request): ?string
    {
        $fromRequest = $request->attributes->get(self::TRACE_ID);
        if (self::valid($fromRequest)) {
            return $fromRequest;
        }

        $fromContext = self::context()?->get(self::TRACE_ID);

        return self::valid($fromContext) ? $fromContext : null;
    }

    /**
     * The one id every surface publishes: the W3C trace id when the feature is on and this request has a
     * valid one, the correlation id otherwise. Never null — a problem without a reference is one a person
     * cannot report and an operator cannot find.
     */
    public static function referenceFor(Request $request): string
    {
        if (self::enabled()) {
            $traceId = self::traceId($request);
            if ($traceId !== null) {
                return $traceId;
            }
        }

        return CorrelationIdFilter::of($request);
    }

    /** The response header the trace id is echoed on; '' disables the echo entirely. */
    public static function header(): string
    {
        return self::enabled() ? self::config()?->string(self::HEADER_KEY, self::DEFAULT_HEADER) ?? self::DEFAULT_HEADER : '';
    }

    private static function enabled(): bool
    {
        return self::config()?->bool(self::ENABLED_KEY, true) ?? true;
    }

    /**
     * The W3C rule, restated: 32 lowercase hex digits and not the reserved all-zero value.
     *
     * @phpstan-assert-if-true string $value
     */
    private static function valid(mixed $value): bool
    {
        return is_string($value)
            && $value !== self::INVALID_TRACE_ID
            && preg_match('/^[0-9a-f]{32}$/', $value) === 1;
    }

    private static function config(): ?Config
    {
        $app = Facade::getFacadeApplication();

        if ($app === null || ! $app->bound(Config::class)) {
            return null;
        }

        /** @var Config $config */
        $config = $app->make(Config::class);

        return $config;
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
