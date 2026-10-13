<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Persistence\ExecutionState;
use Illuminate\Console\Command;

/** `php artisan firefly:orchestration:dlq` — the dead-letter queue, newest first. */
final class DeadLetterCommand extends Command
{
    use OptionReader;

    protected $signature = 'firefly:orchestration:dlq
        {--name= : only this saga/TCC name or workflow id}
        {--limit=50 : at most this many}';

    protected $description = 'List orchestration dead letters.';

    public function handle(DeadLetterService $deadLetters): int
    {
        $limit = $this->stringOption('limit');
        $limit = is_numeric($limit) ? max(1, (int) $limit) : 50;
        $name = $this->stringOption('name');
        $entries = $name === null ? $deadLetters->all($limit) : $deadLetters->byExecutionName($name, $limit);

        $this->table(['Id', 'Execution', 'Correlation id', 'Step', 'Error', 'Retries', 'Created'], array_map(static fn (DeadLetterEntry $entry): array => [
            $entry->id, $entry->pattern->value.' '.$entry->executionName, $entry->correlationId, $entry->stepId ?? '',
            $entry->errorMessage, (string) $entry->retryCount, ExecutionState::instant($entry->createdAt),
        ], $entries));

        return self::SUCCESS;
    }
}
