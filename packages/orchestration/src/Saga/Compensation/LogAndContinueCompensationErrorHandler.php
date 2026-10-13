<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Psr\Log\LoggerInterface;
use Throwable;

/** `log-and-continue`: as `default`, with a warning line per failure. */
final class LogAndContinueCompensationErrorHandler implements CompensationErrorHandler
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        $this->logger->warning("Saga [{$sagaName}] could not compensate step [{$stepId}] (attempt {$attempt}); continuing: {$error->getMessage()}");

        return CompensationErrorResult::Continue;
    }
}
