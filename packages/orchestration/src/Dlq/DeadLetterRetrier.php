<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Dlq;

use Firefly\Orchestration\Core\ExecutionOutcome;
use Firefly\Orchestration\Core\Orchestrator;

/**
 * Retries a dead letter by starting its definition AGAIN with the stored input and headers (spec decision
 * D13): a fresh, fully compensable execution rather than an attempt to patch the failed one. The entry is
 * removed once the new execution has run; if that execution fails too, the engine dead-letters it with
 * retryCount + 1, carried by the RETRY_COUNT header. A retry that cannot start at all (the definition is gone,
 * the pattern is disabled, admission refuses it) throws and leaves the entry where it was.
 */
final class DeadLetterRetrier
{
    public function __construct(
        private readonly DeadLetterService $deadLetters,
        private readonly Orchestrator $orchestrator,
    ) {}

    public function retry(string $id): ExecutionOutcome
    {
        $entry = $this->deadLetters->get($id);
        $headers = $entry->headers + [
            DeadLetterEntry::RETRY_OF_HEADER => $entry->id,
            DeadLetterEntry::RETRY_COUNT_HEADER => (string) $entry->retryCount,
        ];

        $outcome = $this->orchestrator->start($entry->pattern, $entry->executionName, $entry->input, $headers);
        $this->deadLetters->delete($id);

        return $outcome;
    }
}
