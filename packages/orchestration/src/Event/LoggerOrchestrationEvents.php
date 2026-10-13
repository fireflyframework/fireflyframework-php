<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\TccPhase;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The shipped logging listener (jafly OrchestrationLoggerEvents): one line per execution start and end,
 * warnings for step, phase and compensation failures and dead letters, info for workflow state changes. Every
 * line carries the correlation id in its context, which the observability log processors print.
 */
final class LoggerOrchestrationEvents extends OrchestrationEventsAdapter
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function onStart(string $name, string $correlationId, ExecutionPattern $pattern): void
    {
        $this->logger->info("Orchestration {$pattern->value} [{$name}] started.", ['correlationId' => $correlationId]);
    }

    public function onStepFailed(string $name, string $correlationId, string $stepId, Throwable $error, int $attempts): void
    {
        $this->logger->warning("Orchestration [{$name}] step [{$stepId}] failed after {$attempts} attempt(s): {$error->getMessage()}", ['correlationId' => $correlationId, 'exception' => $error]);
    }

    public function onCompleted(string $name, string $correlationId, ExecutionPattern $pattern, bool $success, int $durationMs): void
    {
        $message = "Orchestration {$pattern->value} [{$name}] ".($success ? 'completed' : 'failed')." in {$durationMs} ms.";
        if ($success) {
            $this->logger->info($message, ['correlationId' => $correlationId]);
        } else {
            $this->logger->warning($message, ['correlationId' => $correlationId]);
        }
    }

    public function onCompensationStarted(string $name, string $correlationId): void
    {
        $this->logger->info("Orchestration [{$name}] is compensating.", ['correlationId' => $correlationId]);
    }

    public function onStepCompensationFailed(string $name, string $correlationId, string $stepId, Throwable $error): void
    {
        $this->logger->error("Orchestration [{$name}] could not compensate step [{$stepId}]: {$error->getMessage()}", ['correlationId' => $correlationId, 'exception' => $error]);
    }

    public function onPhaseFailed(string $name, string $correlationId, TccPhase $phase, ?Throwable $error): void
    {
        $this->logger->warning("Orchestration TCC [{$name}] phase {$phase->value} failed".($error === null ? '.' : ": {$error->getMessage()}"), ['correlationId' => $correlationId]);
    }

    public function onWorkflowSuspended(string $name, string $correlationId, string $reason): void
    {
        $this->logger->info("Orchestration workflow [{$name}] suspended".($reason === '' ? '.' : ": {$reason}"), ['correlationId' => $correlationId]);
    }

    public function onWorkflowResumed(string $name, string $correlationId): void
    {
        $this->logger->info("Orchestration workflow [{$name}] resumed.", ['correlationId' => $correlationId]);
    }

    public function onSignalDelivered(string $name, string $correlationId, string $signalName): void
    {
        $this->logger->info("Orchestration workflow [{$name}] received signal [{$signalName}].", ['correlationId' => $correlationId]);
    }

    public function onContinueAsNew(string $name, string $oldCorrelationId, string $newCorrelationId): void
    {
        $this->logger->info("Orchestration workflow [{$name}] continued as new execution [{$newCorrelationId}].", ['correlationId' => $oldCorrelationId]);
    }

    public function onDeadLettered(string $name, string $correlationId, ?string $stepId, string $error): void
    {
        $this->logger->warning("Orchestration [{$name}]".($stepId === null ? '' : " step [{$stepId}]")." was dead-lettered: {$error}", ['correlationId' => $correlationId]);
    }
}
