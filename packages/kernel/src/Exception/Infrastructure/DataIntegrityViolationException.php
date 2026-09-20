<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * A write the database refused because it would break a constraint — a foreign key, a NOT NULL, a CHECK, or a
 * unique index (the last one is the DuplicateKeyException subclass). 409: the request conflicts with the
 * current state of the data, and the client can fix its request. Spring's DataIntegrityViolationException.
 */
class DataIntegrityViolationException extends DataAccessException
{
    public function __construct(
        string $message = 'The write would break a data integrity constraint.',
        string $errorCode = 'DATA_INTEGRITY_VIOLATION',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 409, ErrorSeverity::Warning);
    }
}
