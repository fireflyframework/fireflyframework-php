<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Recovery;

/** The recovery sweep as a scheduled task (lock LOCK, every `persistence.cleanup-interval`). */
final class OrchestrationRecoveryTask
{
    public const string LOCK = 'firefly:orchestration:recovery';

    public function __construct(private readonly RecoveryService $recovery) {}

    public function recover(): RecoveryReport
    {
        return $this->recovery->recover();
    }
}
