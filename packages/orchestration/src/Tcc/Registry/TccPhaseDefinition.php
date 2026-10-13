<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Registry;

use Firefly\Orchestration\Core\Invocation;
use Firefly\Orchestration\Core\MethodInvocation;
use Firefly\Orchestration\Core\Rows;

/** One phase of a participant: what runs and its own timeout/retry/backoff (-1 = inherit). */
final readonly class TccPhaseDefinition
{
    public function __construct(
        public Invocation $invocation,
        public int $timeoutMs = -1,
        public int $retry = -1,
        public int $backoffMs = -1,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['invocation' => $this->invocation->toArray(), 'timeoutMs' => $this->timeoutMs, 'retry' => $this->retry, 'backoffMs' => $this->backoffMs];
    }

    /** @param array<array-key, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(MethodInvocation::fromArray(Rows::map($row, 'invocation')), Rows::int($row, 'timeoutMs', -1), Rows::int($row, 'retry', -1), Rows::int($row, 'backoffMs', -1));
    }
}
