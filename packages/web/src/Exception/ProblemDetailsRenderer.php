<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Error\ProblemMapper;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Renders any throwable as an application/problem+json response via ErrorResponse::fromException (a thin map
 * — status/category/severity live on the exception). Does NOT redefine the error shape (that is kernel/M1's
 * ErrorResponse), and no longer decides what a non-Firefly throwable BECOMES either: that rule moved to
 * ProblemMapper when the HTML error page started needing the same answer, because two copies of it would
 * eventually disagree about the same exception and hand a browser and a client different error codes for
 * one failure.
 */
final class ProblemDetailsRenderer
{
    /**
     * ONE DISCLOSURE SWITCH FOR BOTH RENDERINGS. The HTML page has always been gated by
     * `firefly.web.error-page.trace` (which follows `app.debug`); this path had no gate at all, so the same
     * failure withheld everything from a browser and published a QueryException's SQL and bindings to a
     * client. The settings object is optional so a JSON-only deployment that never bound one still renders —
     * and when it is absent the default is the SAFE one.
     */
    public function __construct(private readonly ?ErrorPageSettings $settings = null) {}

    public function render(Throwable $e, Request $request): Response
    {
        // An absent settings object means the SAFE answer, not the open one — see the constructor.
        $disclose = $this->settings instanceof ErrorPageSettings && $this->settings->trace;

        $exception = ProblemMapper::toFireflyException($e, $disclose);

        $payload = ErrorResponse::fromException(
            $exception,
            instance: $request->path(),
            timestamp: (new DateTimeImmutable)->format(DateTimeInterface::ATOM),
        )->toArray();

        return new Response(
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $exception->httpStatus(),
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
