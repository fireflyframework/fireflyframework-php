<?php

declare(strict_types=1);

namespace Firefly\Web\Exception;

use DateTimeImmutable;
use DateTimeInterface;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Maps any FireflyException to an application/problem+json response via ErrorResponse::fromException (a thin
 * map — status/category/severity live on the exception). A non-Firefly Throwable is first converted to a
 * generic 500 FireflyException. Does NOT redefine the error shape (that is kernel/M1's ErrorResponse).
 */
final class ProblemDetailsRenderer
{
    public function render(Throwable $e, Request $request): Response
    {
        $exception = $e instanceof FireflyException
            ? $e
            : new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
                'INTERNAL_ERROR',
                500,
                ErrorCategory::Internal,
                ErrorSeverity::Error,
                $e,
            );

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
