<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * Exactly one row was required and none came back — EloquentRepository::getById() throws it where findById()
 * would return null. 404: the thing asked for is not there. Spring's EmptyResultDataAccessException.
 */
class EmptyResultDataAccessException extends DataAccessException
{
    public function __construct(
        string $message = 'Expected a result but found none.',
        string $errorCode = 'EMPTY_RESULT',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 404, ErrorSeverity::Warning);
    }
}
