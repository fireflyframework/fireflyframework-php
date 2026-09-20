<?php

declare(strict_types=1);

namespace Firefly\Kernel\Exception\Infrastructure;

use Firefly\Kernel\Error\ErrorSeverity;
use Throwable;

/**
 * The row changed underneath a write that was guarded by a version column. firefly/data's
 * Repository\Locking\OptimisticLockException is the concrete member (it keeps its OPTIMISTIC_LOCK code); this
 * is the kernel-level type the taxonomy and problem+json know. 409. Spring's OptimisticLockingFailureException.
 */
class OptimisticLockingFailureException extends DataAccessException
{
    public function __construct(
        string $message = 'The row changed underneath this write.',
        string $errorCode = 'OPTIMISTIC_LOCKING_FAILURE',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous, 409, ErrorSeverity::Warning);
    }
}
