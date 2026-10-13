<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Throwable;

/**
 * Decides what a failed compensation means (jafly CompensationErrorHandler), selected by
 * `firefly.orchestration.saga.compensation-error-handler`: a built-in name or the class of your own bean.
 * `$attempt` counts the RETRY answers already given for this step (0 on the first failure).
 */
interface CompensationErrorHandler
{
    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult;
}
