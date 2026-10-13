<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Throwable;

/** `fail-fast`: stop compensating at the first failure; the remaining steps stay DONE for an operator. */
final class FailFastCompensationErrorHandler implements CompensationErrorHandler
{
    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        return CompensationErrorResult::FailSaga;
    }
}
