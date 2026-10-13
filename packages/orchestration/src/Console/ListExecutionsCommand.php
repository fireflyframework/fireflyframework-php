<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\ExecutionStatus;
use Firefly\Orchestration\Persistence\ExecutionCriteria;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Illuminate\Console\Command;

/** `php artisan firefly:orchestration:list` — persisted executions, newest first. */
final class ListExecutionsCommand extends Command
{
    use OptionReader;

    protected $signature = 'firefly:orchestration:list
        {--pattern= : saga, tcc or workflow}
        {--status= : an execution status, e.g. failed or waiting}
        {--name= : the saga/TCC name or workflow id}
        {--limit=50 : at most this many}';

    protected $description = 'List persisted saga, TCC and workflow executions.';

    public function handle(ExecutionPersistenceProvider $persistence): int
    {
        $pattern = $this->stringOption('pattern');
        $status = $this->stringOption('status');
        $parsedPattern = $pattern === null ? null : ExecutionPattern::tryFrom(strtoupper($pattern));
        $parsedStatus = $status === null ? null : ExecutionStatus::tryFrom(strtoupper($status));

        if ($pattern !== null && $parsedPattern === null) {
            $this->components->error("Unknown pattern [{$pattern}]: use saga, tcc or workflow.");

            return self::FAILURE;
        }
        if ($status !== null && $parsedStatus === null) {
            $this->components->error("Unknown status [{$status}].");

            return self::FAILURE;
        }

        $limit = $this->stringOption('limit');
        $states = $persistence->find(new ExecutionCriteria($parsedPattern, $parsedStatus, $this->stringOption('name'), is_numeric($limit) ? max(1, (int) $limit) : 50));

        $this->table(['Correlation id', 'Pattern', 'Name', 'Status', 'Updated', 'Waiting on'], array_map(static fn (ExecutionState $state): array => [
            $state->correlationId, $state->pattern->value, $state->executionName, $state->status->value,
            ExecutionState::instant($state->updatedAt), $state->waitingOn ?? '',
        ], $states));

        return self::SUCCESS;
    }
}
