<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * A failure that a retry of the SAME operation may well not repeat — the server went away mid-connection, a
 * serialization failure under SERIALIZABLE, a lost connection Laravel has already recognised. 503 with the
 * retry implied. Spring's TransientDataAccessResourceException.
 */
class TransientDataAccessResourceException extends DataAccessException
{
    public function __construct(
        string $message = 'The data source failed transiently; the operation may succeed if retried.',
        string $errorCode = 'TRANSIENT_DATA_ACCESS_FAILURE',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 503);
    }
}
