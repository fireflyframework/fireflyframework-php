<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Recovery;

use DateTimeImmutable;
use Firefly\Orchestration\Core\Clock;
use Firefly\Orchestration\Core\ExecutionLock;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Saga\Engine\SagaEngine;
use Firefly\Orchestration\Settings\PersistenceSettings;
use Firefly\Orchestration\Settings\RecoverySettings;
use Firefly\Orchestration\Tcc\Engine\TccEngine;
use Firefly\Orchestration\Workflow\Engine\WorkflowEngine;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Finds what crashed processes left behind and deals with it (jafly RecoveryService, PyFly's periodic scan):
 * an execution in a RECOVERABLE status (PENDING, RUNNING, COMPENSATING, TRYING, CONFIRMING, CANCELING) last
 * checkpointed more than `recovery.stale-threshold` ago is stale. `recovery.action` decides: `report` logs it
 * (jafly's behaviour, the default), `resume` continues it from its checkpoint through its engine, `compensate`
 * undoes what completed. Then terminal executions older than `persistence.retention-period` are deleted.
 *
 * A sweep handles at most `backpressure.batch-size` stale executions; one that cannot be recovered (its
 * definition is gone, another process holds its lock) is recorded in the report and the sweep goes on — and
 * does not count against the batch. A failed recovery leaves the row stale exactly as it was, so without that a
 * batch of them (a definition removed in a deploy) would come back first on every sweep and starve every older
 * execution behind them forever; the sweep asks for the next rows instead, as WorkflowEngine::resumeDue() does,
 * looking at no more than ten batches' worth of rows in one sweep.
 * WAITING and SUSPENDED workflows are idle by design and never stale.
 *
 * Rows are selected without a lock, so each one is checked again under its execution lock before anything is
 * done to it: it must still be the row the sweep selected — same status, same last checkpoint. A long step
 * leaves its row looking stale while its process is alive and holds the lock; the sweep waits for that lock,
 * and by the time it has it the run may have finished COMPLETED or CONFIRMED, or checkpointed again. Such a row
 * is reported `skipped` (no longer stale), never resumed and never compensated — the engines' compensate()
 * accepts a COMPLETED or CONFIRMED execution on purpose, as the manual undo API, and without this check the
 * sweep would undo a business transaction that succeeded. The engine calls run inside that same lock
 * (ExecutionLock is re-entrant), so nothing can checkpoint the row between the check and the recovery.
 *
 * What no check can tell apart is a live process whose execution lock EXPIRED (`lock.ttl`) and that has not
 * checkpointed for `recovery.stale-threshold`: from the store it looks exactly like a dead one. Keep every
 * step shorter than `lock.ttl`, and `stale-threshold` well above both.
 */
final class RecoveryService
{
    /** How many batches' worth of rows one sweep may look at while it steps past rows it cannot recover. */
    private const int MAX_BATCHES = 10;

    public function __construct(
        private readonly ExecutionPersistenceProvider $persistence,
        private readonly SagaEngine $sagas,
        private readonly TccEngine $tccs,
        private readonly WorkflowEngine $workflows,
        private readonly ExecutionLock $lock,
        private readonly RecoverySettings $settings,
        private readonly PersistenceSettings $persistenceSettings,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly int $batchSize = 10,
    ) {}

    /** @return list<ExecutionState> at most $limit (the batch size by default), newest update first */
    public function findStale(?int $limit = null): array
    {
        return $this->persistence->findStale($this->staleBefore(), $limit ?? $this->batchSize);
    }

    public function cleanup(): int
    {
        return $this->persistence->cleanup($this->clock->now()->modify(sprintf('-%d seconds', (int) round($this->persistenceSettings->retentionPeriod))));
    }

    public function recover(): RecoveryReport
    {
        if (! $this->settings->enabled) {
            return new RecoveryReport;
        }

        $stale = [];
        $resumed = [];
        $compensated = [];
        $errors = [];
        $skipped = [];

        $before = $this->staleBefore();
        $seen = [];
        do {
            $fresh = 0;
            $failed = false;
            // Wide enough to reach past every row this sweep already saw, so a row that failed is not fetched
            // in place of the next one.
            foreach ($this->persistence->findStale($before, $this->batchSize + count($seen)) as $state) {
                $id = $state->correlationId;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $fresh++;

                try {
                    if ($this->settings->action === RecoverySettings::REPORT) {
                        $stale[] = $id;
                        $this->logger->warning("Orchestration recovery found {$state->pattern->value} [{$state->executionName}] ({$id}) stale in {$state->status->value} since ".ExecutionState::instant($state->updatedAt).'; firefly.orchestration.recovery.action is report, so it is left as it is.', ['correlationId' => $id]);
                    } elseif ($this->recoverIfUnchanged($state)) {
                        $stale[] = $id;
                        if ($this->settings->action === RecoverySettings::RESUME) {
                            $resumed[] = $id;
                        } else {
                            $compensated[] = $id;
                        }
                    } else {
                        // Not an error and not stale: its process is alive and moved it on. It is not counted
                        // against the batch, and findStale() no longer returns it.
                        $skipped[] = $id;
                        $this->logger->info("Orchestration recovery left [{$id}] alone: it changed after the sweep selected it as stale, so the process running it is still alive.", ['correlationId' => $id]);

                        continue;
                    }
                } catch (Throwable $error) {
                    $stale[] = $id;
                    $failed = true;
                    $errors[$id] = $error->getMessage();
                    $this->logger->error("Orchestration recovery of [{$id}] failed: {$error->getMessage()}", ['correlationId' => $id, 'exception' => $error]);
                }

                if (count($stale) - count($errors) >= $this->batchSize) {
                    break 2;
                }
            }
        } while ($failed && $fresh > 0 && count($seen) < $this->batchSize * self::MAX_BATCHES);

        return new RecoveryReport($stale, $resumed, $compensated, $errors, $this->cleanup(), $skipped);
    }

    private function staleBefore(): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d milliseconds', (int) round($this->settings->staleThreshold * 1000)));
    }

    /**
     * Recovers $selected under its execution lock, unless the row changed since the sweep selected it.
     *
     * @return bool false when the row is no longer the stale one the sweep saw (it was left alone)
     */
    private function recoverIfUnchanged(ExecutionState $selected): bool
    {
        return $this->lock->synchronized($selected->correlationId, function () use ($selected): bool {
            $current = $this->persistence->findById($selected->correlationId);
            if ($current === null
                || $current->pattern !== $selected->pattern
                || $current->status !== $selected->status
                || ! $current->status->isRecoverable()
                || ExecutionState::instant($current->updatedAt) !== ExecutionState::instant($selected->updatedAt)) {
                return false;
            }

            if ($this->settings->action === RecoverySettings::RESUME) {
                $this->resume($current);
            } else {
                $this->compensate($current);
            }

            return true;
        });
    }

    private function resume(ExecutionState $state): void
    {
        if ($state->pattern === ExecutionPattern::Saga) {
            $this->sagas->resume($state->correlationId);
        } elseif ($state->pattern === ExecutionPattern::Tcc) {
            $this->tccs->resume($state->correlationId);
        } else {
            $this->workflows->recover($state->correlationId);
        }
    }

    private function compensate(ExecutionState $state): void
    {
        if ($state->pattern === ExecutionPattern::Saga) {
            $this->sagas->compensate($state->correlationId);
        } elseif ($state->pattern === ExecutionPattern::Tcc) {
            $this->tccs->compensate($state->correlationId);
        } else {
            $this->workflows->compensate($state->correlationId);
        }
    }
}
