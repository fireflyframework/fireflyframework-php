<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Throwable;

/**
 * `retry`: try the compensation again up to `maxRetries` times (waiting the step's compensation backoff
 * between tries), then fail the saga. With `retryable` class names, any other error fails the saga at once.
 */
final class RetryWithBackoffCompensationErrorHandler implements CompensationErrorHandler
{
    /**
     * @param  list<string>  $retryable
     */
    public function __construct(
        private readonly int $maxRetries = 3,
        private readonly array $retryable = [],
    ) {}

    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        if (! $this->isRetryable($error)) {
            return CompensationErrorResult::FailSaga;
        }

        return $attempt < $this->maxRetries ? CompensationErrorResult::Retry : CompensationErrorResult::FailSaga;
    }

    private function isRetryable(Throwable $error): bool
    {
        if ($this->retryable === []) {
            return true;
        }

        foreach ($this->retryable as $type) {
            if (is_a($error, $type)) {
                return true;
            }
        }

        return false;
    }
}
