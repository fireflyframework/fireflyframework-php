<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Attributes;

use Attribute;

/**
 * Starts the saga on a schedule (jafly @ScheduledSaga), wired onto Laravel's Schedule under the
 * DistributedLock: exactly one of `cron` or `fixedRate`/`fixedDelay` (milliseconds), `input` as a JSON object.
 * `initialDelay` must stay 0 — there is no honest way to apply it to an argument-carrying scheduled event
 * (spec decision D12), so a non-zero value refuses the scan. Repeatable.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ScheduledSaga
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
