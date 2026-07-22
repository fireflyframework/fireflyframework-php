<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Exception;

use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/**
 * Thrown when a MANDATORY-propagation method runs with no active transaction. The kernel is frozen, so this
 * reuses InfrastructureException (Data -> Kernel is an allowed edge), exactly as M7's BulkheadFullException did.
 */
final class TransactionRequiredException extends InfrastructureException
{
    public function __construct(
        string $message = 'A transaction is required but none is active (propagation MANDATORY).',
        string $errorCode = 'TRANSACTION_REQUIRED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 500, ErrorSeverity::Error, $previous);
    }
}
