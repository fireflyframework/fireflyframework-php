<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Error\ErrorResponse;
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
    public function render(Throwable $e, Request $request): Response
    {
        $exception = ProblemMapper::toFireflyException($e);

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
