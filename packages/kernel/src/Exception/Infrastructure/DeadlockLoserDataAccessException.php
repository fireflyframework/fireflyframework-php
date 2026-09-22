<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Throwable;

/**
 * The database chose this transaction as the victim of a deadlock and rolled it back. Retrying the whole unit
 * of work is the right response, which is why it sits under CannotAcquireLockException rather than beside it.
 * Spring's DeadlockLoserDataAccessException.
 */
class DeadlockLoserDataAccessException extends CannotAcquireLockException
{
    public function __construct(
        string $message = 'The transaction was chosen as the deadlock victim and rolled back.',
        string $errorCode = 'DEADLOCK',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }
}
