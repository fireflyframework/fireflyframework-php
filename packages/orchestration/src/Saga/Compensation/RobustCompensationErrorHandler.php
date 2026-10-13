<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Psr\Log\LoggerInterface;
use Throwable;

/** `robust` (jafly's composite of retry-then-defer and log-and-continue): retry, and when out of retries log and carry on. */
final class RobustCompensationErrorHandler implements CompensationErrorHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly int $maxRetries = 3,
    ) {}

    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        if ($attempt < $this->maxRetries) {
            return CompensationErrorResult::Retry;
        }

        $this->logger->warning("Saga [{$sagaName}] gave up compensating step [{$stepId}] after {$attempt} retries; continuing: {$error->getMessage()}");

        return CompensationErrorResult::Continue;
    }
}
