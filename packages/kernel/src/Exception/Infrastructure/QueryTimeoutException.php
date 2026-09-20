<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * The database cancelled a statement because it ran past its statement timeout (pgsql `statement_timeout`,
 * mysql `max_execution_time`, mariadb `max_statement_time`). 504: the gateway to the data did not answer in
 * time. Spring's QueryTimeoutException.
 */
class QueryTimeoutException extends DataAccessException
{
    public function __construct(
        string $message = 'The query timed out.',
        string $errorCode = 'QUERY_TIMEOUT',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 504);
    }
}
