<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Exception;

use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/**
 * A commit that failed AFTER the transactional method had already thrown an exception the rollback rules kept
 * — a `noRollbackFor` match, or a narrowed `rollbackFor` the exception fell outside. The commit-and-rethrow the
 * rules promised could not happen: the transaction was rolled back instead (TransactionTemplate::commit()
 * unwinds whatever a failed commit leaves open), and two failures now matter. A PHP exception chains one
 * `previous`, so this is Spring's TransactionSystemException with its applicationException: the commit
 * failure is `previous` — translated, with the driver's own exception under it, which is what problem+json
 * and the log see — and the method's exception, already translated, rides on $applicationException, so the
 * caller who wrote noRollbackFor for it can still find it. Spring logs this case as "Application exception
 * overridden by commit exception"; the override is the same here, the application exception just is not
 * dropped. Like its siblings it reuses the kernel's InfrastructureException (Data -> Kernel is an allowed edge).
 */
final class TransactionSystemException extends InfrastructureException
{
    public function __construct(
        public readonly Throwable $applicationException,
        Throwable $commitFailure,
        string $message = 'The transaction could not be committed after the method threw an exception the rollback rules kept.',
        string $errorCode = 'TRANSACTION_SYSTEM_ERROR',
    ) {
        parent::__construct($message, $errorCode, 500, ErrorSeverity::Error, $commitFailure);
    }
}
