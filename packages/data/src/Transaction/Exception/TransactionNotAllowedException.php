<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Exception;

use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/**
 * Thrown when a NEVER-propagation method runs inside an active transaction.
 */
final class TransactionNotAllowedException extends InfrastructureException
{
    public function __construct(
        string $message = 'An active transaction is not allowed here (propagation NEVER).',
        string $errorCode = 'TRANSACTION_NOT_ALLOWED',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, 500, ErrorSeverity::Error, $previous);
    }
}
