<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Throwable;

/** `default`: record the failure on the step and carry on compensating the rest. */
final class DefaultCompensationErrorHandler implements CompensationErrorHandler
{
    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        return CompensationErrorResult::Continue;
    }
}
