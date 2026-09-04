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
 * THREE CASES, AND ONLY THE THIRD IS A DISCLOSURE. A FireflyException already carries its status, code,
 * category and severity and is returned untouched — its message was written by the application FOR the
 * client ("Order 42 does not exist."), which is the whole point of the taxonomy. A Symfony/Illuminate
 * HttpExceptionInterface — the router's own NotFoundHttpException for a URL with no matching route at all,
 * which is a different thing from a matched handler throwing ResourceNotFoundException — keeps its REAL
 * status, and its message is whatever `abort(404, '…')` supplied, so it is equally intended.
 *
 * ANYTHING ELSE IS AN ACCIDENT, AND ITS MESSAGE IS NOT FOR THE CLIENT. A QueryException stringifies the
 * failing SQL *and its bindings*; a TypeError names an absolute path on the server; a PDOException names the
 * host it could not reach. All three were being copied verbatim into `detail` and published as
 * problem+json — in production, with no `app.debug` gate anywhere on that path, while the HTML page next to
 * it withheld everything. `$disclose` closes that: with it false an unhandled throwable answers with a fixed
 * sentence and its real message stays in the exception, where the log has it.
 */
final class ProblemMapper
{
    /** What an unhandled throwable says when its own message may not be published. */
    public const string OPAQUE = 'An unexpected error occurred.';

    /**
     * @param  bool  $disclose  whether an UNHANDLED throwable's own message may reach the client
     */
    public static function toFireflyException(Throwable $e, bool $disclose = true): FireflyException
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
                $disclose && $e->getMessage() !== '' ? $e->getMessage() : self::OPAQUE,
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
