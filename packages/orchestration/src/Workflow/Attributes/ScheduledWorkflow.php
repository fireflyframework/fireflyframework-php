<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Attributes;

use Attribute;

/** Starts the workflow on a schedule; members and refusals as #[ScheduledSaga]. Repeatable. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ScheduledWorkflow
{
    public function __construct(
        public string $cron = '',
        public string $zone = '',
        public bool $enabled = true,
        public int $fixedDelay = -1,
        public int $fixedRate = -1,
        public int $initialDelay = 0,
        public string $input = '{}',
        public string $description = '',
    ) {}
}
