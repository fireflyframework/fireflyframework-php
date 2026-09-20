<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * The data source cannot be reached at all: a refused connection, an unknown host, a missing sqlite file, an
 * exhausted connection limit. 503 DATASOURCE_UNAVAILABLE — the health endpoint reports the same fact as DOWN.
 * Spring's DataAccessResourceFailureException.
 */
class DataAccessResourceFailureException extends DataAccessException
{
    public function __construct(
        string $message = 'The data source is unavailable.',
        string $errorCode = 'DATASOURCE_UNAVAILABLE',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 503);
    }
}
