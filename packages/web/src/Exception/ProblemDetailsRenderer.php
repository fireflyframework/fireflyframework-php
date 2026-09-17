<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Filter\CorrelationIdFilter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders any throwable as an application/problem+json response via ErrorResponse::fromException (a thin map
 * — status/category/severity live on the exception). Does NOT redefine the error shape (that is kernel/M1's
 * ErrorResponse), and no longer decides what a non-Firefly throwable BECOMES either: that rule moved to
 * ProblemMapper when the HTML error page started needing the same answer, because two copies of it would
 * eventually disagree about the same exception and hand a browser and a client different error codes for
 * one failure.
 *
 * THREE THINGS EVERY PROBLEM DOCUMENT NOW CARRIES THAT IT USED TO LACK:
 *
 *   - `traceId`, and the same value on `X-Correlation-Id`. CorrelationIdFilter mints or reads the id at
 *     order -100 and stamps it on every log line of the request; this renderer passed no traceId at all,
 *     so the body a person screenshotted and the log line an operator searched for had nothing in common.
 *     An opaque 5xx sentence names the reference too, so the one action a person can take is possible
 *     from the body alone.
 *   - The headers an HttpExceptionInterface carries. A 405's `Allow` header was set by the router on the
 *     exception and then dropped here, because only Content-Type was ever written on the response.
 *   - `Retry-After` on a 503. The two things that produce one — a starved worker pool, an unreachable
 *     upstream — clear in seconds when they clear at all, and the header is the standard way to say so.
 */
final class ProblemDetailsRenderer
{
    /** Seconds a caller is told to wait before retrying a 503. Short, for the reason in the class comment. */
    public const int RETRY_AFTER_SECONDS = 5;

    /**
     * THE DISCLOSURE GATE IS THE PROBLEM DOCUMENT'S OWN. For one release this path shared the HTML page's
     * `trace` (which follows `app.debug`), and that was the wrong gate for a machine surface: every local
     * and compose environment sets APP_DEBUG, so a console fed by problem+json rendered a QueryException's
     * DSN, tenant id and full statement in a red banner while the HTML page beside it withheld everything.
     * `ErrorPageSettings::$disclose` (`firefly.web.problem.disclose`) is read instead, defaults to false and
     * inherits from nothing. The settings object is optional so a JSON-only deployment that never bound one
     * still renders — and when it is absent the default is the SAFE one.
     */
    public function __construct(private readonly ?ErrorPageSettings $settings = null) {}

    public function render(Throwable $e, Request $request): Response
    {
        // An absent settings object means the SAFE answer, not the open one — see the constructor.
        $disclose = $this->settings instanceof ErrorPageSettings && $this->settings->disclose;

        $correlationId = CorrelationIdFilter::of($request);
        $exception = ProblemMapper::toFireflyException($e, $disclose, $correlationId);

        $payload = ErrorResponse::fromException(
            $exception,
            instance: $request->path(),
            traceId: $correlationId,
            timestamp: (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        )->toArray();

        $headers = [
            'Content-Type' => 'application/problem+json',
            CorrelationIdFilter::HEADER => $correlationId,
        ];

        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $headers[$name] = $value;
            }
        }

        if ($exception->httpStatus() === 503) {
            $headers['Retry-After'] = (string) self::RETRY_AFTER_SECONDS;
        }

        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $exception->httpStatus(),
            $headers,
        );
    }
}
