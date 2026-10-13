<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Execution;

/**
 * The record of every delivery: what was sent, where, how often, and how it ended. Selected by
 * firefly.callbacks.log.store (memory | database). A failure here propagates to the event pipeline, so the
 * broker's retry and dead-letter handling applies — a delivery is never silently left unrecorded.
 */
interface ExecutionLog
{
    /** Inserts, or replaces the execution with the same id (the createdAt of the first record is kept). */
    public function record(CallbackExecution $execution): void;

    public function find(string $id): ?CallbackExecution;

    /**
     * @return list<CallbackExecution> newest first (by updatedAt); empty when $limit is below 1
     */
    public function recent(int $limit = 20, ?CallbackStatus $status = null, ?string $subscriptionId = null): array;
}
