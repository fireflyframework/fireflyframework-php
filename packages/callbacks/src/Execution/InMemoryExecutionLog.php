<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Execution;

/** log.store=memory (the default): the deliveries of this process — enough for tests and for `list` in a worker. */
final class InMemoryExecutionLog implements ExecutionLog
{
    /** @var array<string, CallbackExecution> */
    private array $executions = [];

    /** @var array<string, int> record sequence, the tie-breaker for equal updatedAt (latest record first) */
    private array $sequence = [];

    private int $next = 0;

    public function record(CallbackExecution $execution): void
    {
        $existing = $this->executions[$execution->id] ?? null;
        if ($existing !== null && $existing->createdAt !== $execution->createdAt) {
            $execution = new CallbackExecution(
                $execution->id,
                $execution->subscriptionId,
                $execution->eventId,
                $execution->eventType,
                $execution->url,
                $execution->body,
                $execution->status,
                $execution->attempts,
                $execution->responseStatus,
                $execution->lastError,
                $existing->createdAt,
                $execution->updatedAt,
            );
        }

        $this->executions[$execution->id] = $execution;
        $this->sequence[$execution->id] = ++$this->next;
    }

    public function find(string $id): ?CallbackExecution
    {
        return $this->executions[$id] ?? null;
    }

    public function recent(int $limit = 20, ?CallbackStatus $status = null, ?string $subscriptionId = null): array
    {
        if ($limit < 1) {
            return [];
        }

        $matching = array_filter(
            $this->executions,
            static fn (CallbackExecution $e): bool => ($status === null || $e->status === $status) && ($subscriptionId === null || $e->subscriptionId === $subscriptionId),
        );

        uasort($matching, fn (CallbackExecution $a, CallbackExecution $b): int => [$b->updatedAt, $this->sequence[$b->id] ?? 0] <=> [$a->updatedAt, $this->sequence[$a->id] ?? 0]);

        return array_slice(array_values($matching), 0, $limit);
    }
}
