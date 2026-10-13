<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Engine;

use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Core\TccPhase;
use Throwable;

/** How the phases ended: CONFIRMED, CANCELED, or FAILED, and which participant failed in which phase. */
final readonly class TccRunOutcome
{
    public function __construct(
        public ExecutionStatus $status,
        public ?string $failedParticipantId = null,
        public ?TccPhase $failedPhase = null,
        public ?Throwable $error = null,
    ) {}
}
