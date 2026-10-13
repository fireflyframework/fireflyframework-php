<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Exception;

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\FireflyException;
use Throwable;

/**
 * The query-side twin of CommandProcessingException: DefaultQueryBus wraps any handler/stage throwable in this
 * (unless already one), preserving a FireflyException cause's code/httpStatus/category/severity and its RFC 9457
 * extension members and title, else internal/500.
 */
final class QueryProcessingException extends CqrsException
{
    public function __construct(string $queryClass, Throwable $cause)
    {
        parent::__construct(
            "Processing query [{$queryClass}] failed: {$cause->getMessage()}",
            /*
             | The cause's OWN code, not a generic one. The error code is part of a fault's identity in
             | exactly the way its status, category and severity are, and copying three of the four left
             | every domain failure indistinguishable on the wire — a duplicate as `409 QUERY_PROCESSING_ERROR`,
             | a missing row as `404 QUERY_PROCESSING_ERROR` — so callers had nothing to branch on and
             | worked around it by rethrowing getPrevious() in every controller. A cause that is not a
             | FireflyException has no code of its own and still yields the generic one below.
             */
            $cause instanceof FireflyException ? $cause->errorCode() : 'QUERY_PROCESSING_ERROR',
            $cause instanceof FireflyException ? $cause->httpStatus() : 500,
            $cause instanceof FireflyException ? $cause->category() : ErrorCategory::Internal,
            $cause instanceof FireflyException ? $cause->severity() : ErrorSeverity::Error,
            $cause,
            /*
             | And the cause's RFC 9457 extension members and title, for the same reason: they are what the
             | problem document carries beside the code. ErrorResponse::fromException() reads them off the
             | exception it is handed, which over the bus is this wrapper, so a refused rule's
             | `requiredAuthorities` (or a fault's `field`, `allowed`, own title) was dropped from every
             | query while the same fault thrown from a controller published it.
             */
            $cause instanceof FireflyException ? $cause->extensions() : [],
            $cause instanceof FireflyException ? $cause->title() : null,
        );
    }
}
