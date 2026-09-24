<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ProblemMapper;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Trace\TraceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use JsonException;
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
 *   - `traceId` IS the W3C trace id now, and `correlationId` is its own member beside it. The first bullet
 *     above described the release in which `traceId` carried the correlation id: a member named after a
 *     trace holding a value no trace backend had ever heard of. TraceContext::referenceFor() publishes the
 *     request's W3C trace id when tracing is on and this request has a valid one, and FALLS BACK to the
 *     correlation id — byte for byte what this document carried before — when it does not, so the only
 *     thing that changes the value is switching tracing on. The correlation id is not absorbed: it keeps
 *     `X-Correlation-Id` untouched and gains `correlationId`, and the trace id is echoed on its own header
 *     (`firefly.web.trace-id.header`, `X-Trace-Id` by default, '' to disable) only when there is one.
 *   - A BODY, unconditionally. The encoder is total: an invalid UTF-8 byte is substituted rather than
 *     raised, and anything json_encode still refuses falls back to a minimal document — one that keeps every
 *     standard member, the reference among them, and drops only the values that can be the cause. This
 *     method used to throw JsonException out of the error handler on a latin-1 byte in a driver message,
 *     which turned a described failure into a blank 500 with no document at all.
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
        $reference = TraceContext::referenceFor($request);
        $exception = ProblemMapper::toFireflyException($e, $disclose, $reference);

        // The correlation id keeps its own member beside the trace id. They are usually different values
        // with different jobs — one finds the trace, one matches the caller's own request log — and a
        // document that published only the first would make the second unrecoverable from the response.
        // It is passed THROUGH ErrorResponse rather than written onto the array afterwards: the DTO's
        // member list is what the published OpenAPI component is generated and guarded from, so a member
        // appended here would be one no generated client decodes.
        $payload = ErrorResponse::fromException(
            $exception,
            instance: $request->path(),
            traceId: $reference,
            timestamp: (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
            correlationId: $correlationId,
        )->toArray();

        $headers = [
            'Content-Type' => 'application/problem+json',
            CorrelationIdFilter::HEADER => $correlationId,
        ];

        $traceHeader = TraceContext::header();
        $traceId = TraceContext::traceId($request);
        if ($traceHeader !== '' && $traceId !== null) {
            $headers[$traceHeader] = $traceId;
        }

        if ($e instanceof HttpExceptionInterface) {
            foreach ($e->getHeaders() as $name => $value) {
                $headers[$name] = $value;
            }
        }

        if ($exception->httpStatus() === 503) {
            $headers['Retry-After'] = (string) self::RETRY_AFTER_SECONDS;
        }

        return new Response(
            self::encode($payload),
            $exception->httpStatus(),
            $headers,
        );
    }

    /**
     * The payload as JSON, whatever the payload turns out to contain.
     *
     * THE RENDERER RUNS WHILE THE APPLICATION IS ALREADY FAILING, and json_encode had a live failure mode on
     * exactly that path: JSON_THROW_ON_ERROR turns a single byte that is not valid UTF-8 — anywhere in the
     * document — into a JsonException thrown OUT of this method, so the error handler fails while handling
     * the error and the caller receives no document at all. Those bytes are not exotic: a driver message
     * quoting a latin-1 column value, a request header echoed into an extension member at the throw site, a
     * file name off a filesystem that is not UTF-8.
     *
     * JSON_INVALID_UTF8_SUBSTITUTE answers that case properly — the offending bytes become U+FFFD and the
     * document is still the document. The try/catch is the belt to that pair of braces: recursion, a
     * resource, an INF or NAN an application put in an extension member are all things json_encode still
     * refuses, and none of them is a reason to answer a caller with nothing.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function encode(array $payload): string
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $json = false;
        }

        return is_string($json) ? $json : self::minimal($payload);
    }

    /**
     * A document that CANNOT fail to encode: the standard members ErrorResponse declares, each rebuilt from
     * a value whose type is checked here rather than trusted, with every string passing through the same
     * substitution.
     *
     * WHAT IS DROPPED IS WHAT COULD BE THE CAUSE, AND NOTHING ELSE. Only a NON-string reaches this branch —
     * JSON_INVALID_UTF8_SUBSTITUTE has already answered every bad byte — so what brought us here is an
     * extension member an application chose at the throw site, a FieldError::$rejectedValue (`mixed`, so
     * literally whatever the client sent), or a structure too deep or too circular to walk. `errors` and the
     * open namespace therefore go. The standard members STAY, because ErrorResponse declares every one of
     * them `?string` (`int`, for the status) and array_diff_key() keeps a same-named extension out of the
     * document, so not one of them can be why we are here and dropping them buys nothing:
     *
     *   - `category` and `severity` pinned to Internal/Error for every exception publishes a document that
     *     contradicts its own status and code — a 409 whose category reads `internal`, a 422 a generated
     *     client branching on `category == 'validation'` renders down the wrong arm. They are re-derived
     *     through tryFrom() rather than copied so the published schema's enum constraint holds whatever the
     *     payload turns out to say.
     *   - `detail`, `traceId` and `correlationId` dropped publishes exactly the document whose body a person
     *     cannot correlate, against this class's promise that quoting the reference is possible from the body
     *     alone — and replaces an authored sub-500 sentence that ProblemMapper's disclosure gate had already
     *     cleared for publication with an opaque internal one that withholds nothing it had not already let
     *     through.
     *
     * The literal at the bottom is unreachable and is written anyway: a renderer on the error path does not
     * get to assume.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function minimal(array $payload): string
    {
        $status = is_int($payload['status'] ?? null) ? $payload['status'] : 500;
        $category = ErrorCategory::tryFrom(self::member($payload, 'category', '')) ?? ErrorCategory::Internal;
        $severity = ErrorSeverity::tryFrom(self::member($payload, 'severity', '')) ?? ErrorSeverity::Error;

        $document = [
            'status' => $status,
            'title' => self::member($payload, 'title', ErrorResponse::titleFor($status)),
            'code' => self::member($payload, 'code', 'INTERNAL_ERROR'),
            'category' => $category->value,
            'severity' => $severity->value,
            'detail' => self::member($payload, 'detail', ProblemMapper::OPAQUE),
        ];

        // The rest are optional in the DOCUMENT as well as on the DTO — toArray() writes each one only when
        // it is non-null — so an absent member stays absent here rather than becoming an invented empty
        // string. The order is STANDARD_MEMBERS', so the degraded document reads like the full one.
        foreach (['type', 'instance', 'traceId', 'correlationId', 'timestamp'] as $member) {
            $value = $payload[$member] ?? null;

            if (is_string($value)) {
                $document[$member] = $value;
            }
        }

        $json = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        return is_string($json)
            ? $json
            : '{"status":500,"title":"Internal Server Error","code":"INTERNAL_ERROR","category":"internal","severity":"error"}';
    }

    /**
     * One standard member of the payload, when it is the string ErrorResponse declares it to be.
     *
     * The type check is not ceremony: minimal() runs because something in this payload was not what the
     * document's shape says it is, and a member read on trust here would take the fallback down with it.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function member(array $payload, string $name, string $fallback): string
    {
        $value = $payload[$name] ?? null;

        return is_string($value) ? $value : $fallback;
    }
}
