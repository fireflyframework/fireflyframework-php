<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * A query that was supposed to return at most one row returned more — findOneByExample() over a probe that is
 * not selective enough. A programming error (the probe, not the data), so 500. Spring's
 * IncorrectResultSizeDataAccessException.
 */
class IncorrectResultSizeDataAccessException extends DataAccessException
{
    public function __construct(
        string $message = 'The query returned a different number of rows than expected.',
        string $errorCode = 'INCORRECT_RESULT_SIZE',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}
