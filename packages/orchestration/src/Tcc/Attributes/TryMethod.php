<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/** The participant's TRY: reserve the resource; receives the participant input. -1 inherits from #[TccParticipant] and #[Tcc]. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class TryMethod
{
    public function __construct(
        public int $timeoutMs = -1,
        public int $retry = -1,
        public int $backoffMs = -1,
    ) {}
}
