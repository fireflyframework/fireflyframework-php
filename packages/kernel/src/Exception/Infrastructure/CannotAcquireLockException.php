<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * A row or table lock could not be obtained in time — a lock wait timeout, a `NOWAIT` refusal, sqlite's BUSY
 * or LOCKED. 409 because retrying the same request later is the correct client behaviour; the deadlock case
 * is the DeadlockLoserDataAccessException subclass. Spring's CannotAcquireLockException.
 */
class CannotAcquireLockException extends DataAccessException
{
    public function __construct(
        string $message = 'The lock could not be acquired.',
        string $errorCode = 'LOCK_NOT_ACQUIRED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 409, ErrorSeverity::Warning);
    }
}
