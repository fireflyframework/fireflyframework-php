<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * A #[Transactional(timeout:)] unit of work ran past its deadline; firefly/data rolled it back before throwing
 * this, so nothing it wrote survives. 504 like every timeout. Spring's TransactionTimedOutException.
 */
class TransactionTimedOutException extends TimeoutException
{
    public function __construct(
        string $message = 'The transaction exceeded its timeout and was rolled back.',
        string $errorCode = 'TRANSACTION_TIMED_OUT',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}
