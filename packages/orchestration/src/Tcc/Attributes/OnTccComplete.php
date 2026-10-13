<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/** Called after the TCC CONFIRMED, with the ExecutionContext and/or the TccResult. */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class OnTccComplete
{
    public function __construct(
        public bool $async = false,
        public int $priority = 0,
    ) {}
}
