<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Recovery;

use DateTimeImmutable;
use Firefly\Orchestration\Core\Clock;
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
                $stale[] = $id;

                try {
                    if ($this->settings->action === RecoverySettings::RESUME) {
                        $this->resume($state);
                        $resumed[] = $id;
                    } elseif ($this->settings->action === RecoverySettings::COMPENSATE) {
                        $this->compensate($state);
                        $compensated[] = $id;
                    } else {
                        $this->logger->warning("Orchestration recovery found {$state->pattern->value} [{$state->executionName}] ({$id}) stale in {$state->status->value} since ".ExecutionState::instant($state->updatedAt).'; firefly.orchestration.recovery.action is report, so it is left as it is.', ['correlationId' => $id]);
                    }
                } catch (Throwable $error) {
                    $failed = true;
                    $errors[$id] = $error->getMessage();
                    $this->logger->error("Orchestration recovery of [{$id}] failed: {$error->getMessage()}", ['correlationId' => $id, 'exception' => $error]);
                }

                if (count($stale) - count($errors) >= $this->batchSize) {
                    break 2;
                }
            }
        } while ($failed && $fresh > 0 && count($seen) < $this->batchSize * self::MAX_BATCHES);

        return new RecoveryReport($stale, $resumed, $compensated, $errors, $this->cleanup());
    }

    private function staleBefore(): DateTimeImmutable
    {
        return $this->clock->now()->modify(sprintf('-%d milliseconds', (int) round($this->settings->staleThreshold * 1000)));
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
