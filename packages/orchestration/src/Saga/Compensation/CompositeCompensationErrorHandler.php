<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

use Throwable;

/** Asks each handler in turn; the first answer other than CONTINUE wins (jafly CompositeCompensationErrorHandler). */
final class CompositeCompensationErrorHandler implements CompensationErrorHandler
{
    /**
     * @param  list<CompensationErrorHandler>  $handlers
     */
    public function __construct(private readonly array $handlers) {}

    public function handle(string $sagaName, string $stepId, Throwable $error, int $attempt): CompensationErrorResult
    {
        foreach ($this->handlers as $handler) {
            $result = $handler->handle($sagaName, $stepId, $error, $attempt);
            if ($result !== CompensationErrorResult::Continue) {
                return $result;
            }
        }

        return CompensationErrorResult::Continue;
    }
}
