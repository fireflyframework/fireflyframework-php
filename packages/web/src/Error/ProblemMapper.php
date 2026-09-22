<?php

declare(strict_types=1);

namespace Firefly\Web\Error;

use Error;
use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorResponse;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Throwable;

/**
 * The one rule for turning ANY throwable into the FireflyException the error shape is derived from.
 *
 * It is shared rather than duplicated because two renderers now describe the same failure — problem+json for
 * a client, an HTML page for a browser — and they must agree. Two copies of this mapping would eventually
 * disagree about the status of an HttpExceptionInterface or the code of an unhandled RuntimeException, and
 * the symptom would be a support ticket quoting an error code that appears nowhere in the logs.
 *
 * FOUR CASES, AND ONLY THE LAST IS A DISCLOSURE. A FireflyException already carries its status, code,
 * category and severity and is returned untouched — its message was written by the application FOR the
 * client ("Order 42 does not exist."), which is the whole point of the taxonomy. PHP's own execution-time
 * limit is named next, because a reader can act on it: the request was not wrong, the server stopped it,
 * and a 503 with `Retry-After` says so where a 500 with the engine's sentence says "your fault, no idea
 * why". A Symfony/Illuminate HttpExceptionInterface keeps its REAL status; its message is the author's when
 * `abort(404, '…')` supplied one, and the ROUTER's when the router raised it — and the router's sentences
 * ("The route api/x could not be found.", "The GET method is not supported for route api/x. Supported
 * methods: POST.") are replaced with ones written for a person, because "route" is the framework's word,
 * the path is already in `instance`, and the allowed methods belong in an `allowed` extension member and
 * the `Allow` header, not inside a sentence a client would have to parse.
 *
 * ANYTHING ELSE IS AN ACCIDENT, AND ITS MESSAGE IS NOT FOR THE CLIENT. A QueryException stringifies the
 * failing SQL *and its bindings*; a TypeError names an absolute path on the server; a PDOException names the
 * host it could not reach. All three were being copied verbatim into `detail` and published as
 * problem+json — in production, with no `app.debug` gate anywhere on that path, while the HTML page next to
 * it withheld everything. `$disclose` closes that: with it false an unhandled throwable answers with a fixed
 * sentence and its real message stays in the exception, where the log has it. When the caller knows the
 * request's correlation id it passes it as `$reference`, and the fixed sentence names it — so the one thing
 * a person can do with an opaque 5xx, quote it, is possible from the body alone.
 */
final class ProblemMapper
{
    /** What an unhandled throwable says when its own message may not be published and no reference is known. */
    public const string OPAQUE = 'An unexpected error occurred.';

    /** The same, when the request's correlation id is known; `%s` is the reference. */
    public const string OPAQUE_WITH_REFERENCE = 'An unexpected error occurred. It has been logged; quote reference %s if you report it.';

    /** What a URL that matches no route says, in place of the router's "The route … could not be found." */
    public const string NOTHING_HERE = 'There is nothing at this address.';

    /** The code for a request PHP's execution-time limit stopped. */
    public const string EXECUTION_TIME_EXCEEDED = 'EXECUTION_TIME_EXCEEDED';

    /**
     * @param  bool  $disclose  whether an UNHANDLED throwable's own message may reach the client
     * @param  ?string  $reference  the request's correlation id, named in the opaque sentence when known
     */
    public static function toFireflyException(Throwable $e, bool $disclose = true, ?string $reference = null): FireflyException
    {
        return match (true) {
            $e instanceof FireflyException => $e,
            self::isExecutionTimeLimit($e) => self::executionTimeExceeded($e, $reference),
            $e instanceof MethodNotAllowedHttpException => self::methodNotAllowed($e),
            $e instanceof HttpExceptionInterface => new FireflyException(
                self::httpMessage($e),
                self::errorCode($e->getStatusCode()),
                $e->getStatusCode(),
                ErrorCategory::Framework,
                ErrorSeverity::Warning,
                $e,
            ),
            default => new FireflyException(
                $disclose && $e->getMessage() !== '' ? $e->getMessage() : self::opaque($reference),
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

    /**
     * An HttpException's message is the author's when abort() supplied one and the router's when the router
     * raised it. Laravel's router has phrased its 404 as "The route {uri} could not be found." for its whole
     * life; that exact shape is the only one replaced, so `abort(404, 'No such tenant.')` still reaches the
     * client verbatim.
     */
    private static function httpMessage(HttpExceptionInterface $e): string
    {
        $message = $e->getMessage();

        if ($message === '') {
            return $e->getStatusCode() === 404 ? self::NOTHING_HERE : self::statusText($e->getStatusCode());
        }

        if ($e->getStatusCode() === 404 && str_starts_with($message, 'The route ') && str_ends_with($message, ' could not be found.')) {
            return self::NOTHING_HERE;
        }

        return $message;
    }

    /**
     * The verbs a 405 permits live in the exception's `Allow` header, which the router always sets. HEAD is
     * dropped from the sentence and the extension because Symfony adds it beside every GET and no person
     * chooses it; it stays in the header the renderer copies through, where the standard wants it.
     */
    private static function methodNotAllowed(MethodNotAllowedHttpException $e): FireflyException
    {
        $header = $e->getHeaders()['Allow'] ?? '';
        $allowed = array_values(array_filter(array_map(
            static fn (string $method): string => strtoupper(trim($method)),
            explode(',', is_string($header) ? $header : ''),
        ), static fn (string $method): bool => $method !== '' && $method !== 'HEAD'));

        $sentence = match (count($allowed)) {
            0 => 'This address does not accept that method.',
            1 => "This address only accepts {$allowed[0]}.",
            default => 'This address only accepts '.implode(', ', array_slice($allowed, 0, -1)).' or '.$allowed[count($allowed) - 1].'.',
        };

        return new FireflyException(
            $sentence,
            'METHOD_NOT_ALLOWED',
            405,
            ErrorCategory::Framework,
            ErrorSeverity::Warning,
            $e,
            extensions: ['allowed' => $allowed],
            title: ErrorResponse::titleFor(405),
        );
    }

    /**
     * PHP's own message for the limit is the only signal there is: the engine raises it as E_ERROR from
     * inside whatever line happened to be executing, Symfony's handler turns that into a FatalError (an
     * \Error), and nothing on the way carries a code. The prefix has been stable since PHP 4. Matched on
     * \Error rather than on Symfony's FatalError class so this package needs no dependency on the error
     * handler that happens to have caught it.
     */
    private static function isExecutionTimeLimit(Throwable $e): bool
    {
        return $e instanceof Error && str_starts_with($e->getMessage(), 'Maximum execution time of ');
    }

    private static function executionTimeExceeded(Throwable $e, ?string $reference): FireflyException
    {
        $seconds = preg_match('/^Maximum execution time of (\d+) second/', $e->getMessage(), $m) === 1
            ? (int) $m[1]
            : (int) ini_get('max_execution_time');

        $sentence = "The server stopped this request after {$seconds} seconds; try again in a moment.";
        if ($reference !== null) {
            $sentence = "The server stopped this request after {$seconds} seconds. It has been logged; quote reference {$reference} if you report it.";
        }

        return new FireflyException(
            $sentence,
            self::EXECUTION_TIME_EXCEEDED,
            503,
            ErrorCategory::Infrastructure,
            ErrorSeverity::Error,
            $e,
        );
    }

    private static function opaque(?string $reference): string
    {
        return $reference === null ? self::OPAQUE : sprintf(self::OPAQUE_WITH_REFERENCE, $reference);
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
