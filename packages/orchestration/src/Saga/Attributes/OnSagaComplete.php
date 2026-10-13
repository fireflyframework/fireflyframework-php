<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * Called after the saga COMPLETED, with any of: the ExecutionContext, the SagaResult. Higher `priority` runs
 * first; `async` runs after the synchronous callbacks (spec decision D11). A callback failure is logged and
 * never changes the result.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnSagaComplete
{
    public function __construct(
        public bool $async = false,
        public int $priority = 0,
    ) {}
}
