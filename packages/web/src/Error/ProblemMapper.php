<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The one rule for turning ANY throwable into the FireflyException the error shape is derived from.
 *
 * It is shared rather than duplicated because two renderers now describe the same failure — problem+json for
 * a client, an HTML page for a browser — and they must agree. Two copies of this mapping would eventually
 * disagree about the status of an HttpExceptionInterface or the code of an unhandled RuntimeException, and
 * the symptom would be a support ticket quoting an error code that appears nowhere in the logs.
 *
 * THREE CASES. A FireflyException already carries its status, code, category and severity and is returned
 * untouched. A Symfony/Illuminate HttpExceptionInterface — the router's own NotFoundHttpException for a URL
 * with no matching route at all, which is a different thing from a matched handler throwing
 * ResourceNotFoundException — keeps its REAL status; without this branch every unrouted URL rendered as a
 * 500. Anything else is a genuine 500.
 */
final class ProblemMapper
{
    public static function toFireflyException(Throwable $e): FireflyException
    {
        return match (true) {
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
    }

    public static function statusText(int $status): string
    {
        /** @var array<int, string> $texts */
        $texts = Response::$statusTexts;

        return $texts[$status] ?? 'HTTP Error';
    }

    private static function errorCode(int $status): string
    {
        return match ($status) {
            404 => 'RESOURCE_NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            default => 'HTTP_'.$status,
        };
    }
}
