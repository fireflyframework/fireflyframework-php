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
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Maps any FireflyException to an application/problem+json response via ErrorResponse::fromException (a thin
 * map — status/category/severity live on the exception). A Symfony/Illuminate HttpExceptionInterface (e.g. the
 * router's own NotFoundHttpException for a URL with NO matching route at all — distinct from a Firefly
 * ResourceNotFoundException thrown by a MATCHED route's handler) is converted preserving its REAL status code
 * (bug fix, T12/actuator-T10: this branch was missing, so every unmatched route rendered as a 500 INTERNAL_ERROR
 * for any JSON client — caught by the actuator HTTP capstone's master-gate-off assertion, which hits a
 * genuinely unrouted /actuator/health). Any OTHER Throwable is converted to a generic 500 FireflyException. Does
 * NOT redefine the error shape (that is kernel/M1's ErrorResponse).
 */
final class ProblemDetailsRenderer
{
    public function render(Throwable $e, Request $request): Response
    {
        $exception = match (true) {
            $e instanceof FireflyException => $e,
            $e instanceof HttpExceptionInterface => new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : self::statusText($e->getStatusCode()),
                self::errorCode($e->getStatusCode()),
                $e->getStatusCode(),
                ErrorCategory::Framework,
                ErrorSeverity::Warning,
                $e,
            ),
            default => new FireflyException(
                $e->getMessage() !== '' ? $e->getMessage() : 'Internal Server Error',
                'INTERNAL_ERROR',
                500,
                ErrorCategory::Internal,
                ErrorSeverity::Error,
                $e,
            ),
        };

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

    private static function errorCode(int $status): string
    {
        return match ($status) {
            404 => 'RESOURCE_NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            default => 'HTTP_'.$status,
        };
    }

    private static function statusText(int $status): string
    {
        /** @var array<int, string> $texts */
        $texts = Response::$statusTexts;

        return $texts[$status] ?? 'HTTP Error';
    }
}
