<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Workflow\Registry;

/** A signal a step waits for; `timeoutMs` 0 waits forever. */
final readonly class SignalWait
{
    public function __construct(
        public string $name,
        public int $timeoutMs = 0,
    ) {}
}
