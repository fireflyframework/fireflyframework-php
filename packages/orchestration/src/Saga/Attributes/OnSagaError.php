<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * Called after a failed saga was compensated, with any of: the ExecutionContext, the Throwable, the
 * SagaResult. `errorTypes` restricts it to those exception classes; `suppressError` turns the outcome into
 * COMPLETED (the compensation still ran).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnSagaError
{
    /**
     * @param  list<string>  $errorTypes  exception class names; empty matches every error
     */
    public function __construct(
        public array $errorTypes = [],
        public bool $suppressError = false,
        public bool $async = false,
        public int $priority = 0,
    ) {}
}
