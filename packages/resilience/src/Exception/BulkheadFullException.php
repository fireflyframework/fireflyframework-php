<?php

declare(strict_types=1);

namespace Firefly\Resilience\Exception;

use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/**
 * A bulkhead rejected a call because every concurrency permit is held. The kernel is frozen, so this lives
 * in the resilience package (Resilience→Kernel is allowed); the other five patterns reuse the shipped kernel
 * infrastructure exceptions verbatim.
 */
class BulkheadFullException extends InfrastructureException
{
    public function __construct(
        string $message = 'Bulkhead is full',
        string $errorCode = 'BULKHEAD_FULL',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 503, ErrorSeverity::Warning, $previous);
    }
}
