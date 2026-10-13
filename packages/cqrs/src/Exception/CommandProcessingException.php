<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

/**
 * The wrapper DefaultCommandBus re-throws any handler/stage throwable in (unless it is already a
 * CommandProcessingException, in which case the bus re-throws as-is). IDENTITY-PRESERVING: when the cause is a
 * FireflyException, its errorCode/httpStatus/category/severity are copied so an expected client/domain fault (validation,
 * not-found) keeps its own kernel category and is NOT masked into a generic 500, and its RFC 9457 extension members and
 * title are copied so the problem document says what the cause would have said on its own; a plain Throwable becomes
 * internal/500. The message keeps the command class and the cause's message for the log; the published detail() is
 * the cause's own detail, or null (opaque on the wire) when the cause is not a FireflyException. Carries the command class + the cause as `previous` (design §2.3 / pyfly command/bus.py:153-164).
 */
final class CommandProcessingException extends CqrsException
{
    public function __construct(string $commandClass, Throwable $cause)
    {
        parent::__construct(
            "Processing command [{$commandClass}] failed: {$cause->getMessage()}",
            /*
             | The cause's OWN code, not a generic one. The error code is part of a fault's identity in
             | exactly the way its status, category and severity are, and copying three of the four left
             | every domain failure indistinguishable on the wire — a duplicate as `409 COMMAND_PROCESSING_ERROR`,
             | a missing row as `404 COMMAND_PROCESSING_ERROR` — so callers had nothing to branch on and
             | worked around it by rethrowing getPrevious() in every controller. A cause that is not a
             | FireflyException has no code of its own and still yields the generic one below.
             */
            $cause instanceof FireflyException ? $cause->errorCode() : 'COMMAND_PROCESSING_ERROR',
            $cause instanceof FireflyException ? $cause->httpStatus() : 500,
            $cause instanceof FireflyException ? $cause->category() : ErrorCategory::Internal,
            $cause instanceof FireflyException ? $cause->severity() : ErrorSeverity::Error,
            $cause,
            /*
             | And the cause's RFC 9457 extension members and title, for the same reason: they are what the
             | problem document carries beside the code. ErrorResponse::fromException() reads them off the
             | exception it is handed, which over the bus is this wrapper, so a refused rule's
             | `requiredAuthorities` (or a fault's `field`, `allowed`, own title) was dropped from every
             | command while the same fault thrown from a controller published it.
             */
            $cause instanceof FireflyException ? $cause->extensions() : [],
            $cause instanceof FireflyException ? $cause->title() : null,
        );

        /*
         | And what the client may read, which is NOT the message above. The message names the command
         | class and quotes the cause verbatim — the log's line. On the wire an authored cause says its own
         | sentence, exactly as it would thrown from a controller; anything else (a QueryException with its
         | SQL and bindings, a TypeError naming a path) has no sentence for a client, so the detail is null
         | and the web layer answers with its opaque one, as it does for the same throwable unwrapped.
         */
        $this->withDetail($cause instanceof FireflyException ? $cause->detail() : null);
    }
}
