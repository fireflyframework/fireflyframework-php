<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\TccPhase;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Throwable;

/** Records every hook in order; `hooks()` is the sequence a test asserts. */
final class RecordingOrchestrationEvents implements OrchestrationEvents
{
    /** @var list<array{hook: string, args: list<mixed>}> */
    public array $calls = [];

    /** @return list<string> */
    public function hooks(): array
    {
        return array_map(static fn (array $call): string => $call['hook'], $this->calls);
    }

    public function count(string $hook): int
    {
        return count(array_filter($this->calls, static fn (array $call): bool => $call['hook'] === $hook));
    }

    /** @param list<mixed> $args */
    private function record(string $hook, array $args): void
    {
        $this->calls[] = ['hook' => $hook, 'args' => $args];
    }

    public function onStart(string $name, string $correlationId, ExecutionPattern $pattern): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $pattern]);
    }

    public function onStepStarted(string $name, string $correlationId, string $stepId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId]);
    }

    public function onStepSuccess(string $name, string $correlationId, string $stepId, int $attempts, int $latencyMs): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId, $attempts, $latencyMs]);
    }

    public function onStepFailed(string $name, string $correlationId, string $stepId, Throwable $error, int $attempts): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId, $error->getMessage(), $attempts]);
    }

    public function onStepSkipped(string $name, string $correlationId, string $stepId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId]);
    }

    public function onStepSkippedIdempotent(string $name, string $correlationId, string $stepId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId]);
    }

    public function onCompleted(string $name, string $correlationId, ExecutionPattern $pattern, bool $success, int $durationMs): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $pattern, $success]);
    }

    public function onCompensationStarted(string $name, string $correlationId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId]);
    }

    public function onStepCompensated(string $name, string $correlationId, string $stepId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId]);
    }

    public function onStepCompensationFailed(string $name, string $correlationId, string $stepId, Throwable $error): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId, $error->getMessage()]);
    }

    public function onPhaseStarted(string $name, string $correlationId, TccPhase $phase): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $phase]);
    }

    public function onPhaseCompleted(string $name, string $correlationId, TccPhase $phase, int $durationMs): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $phase]);
    }

    public function onPhaseFailed(string $name, string $correlationId, TccPhase $phase, ?Throwable $error): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $phase, $error?->getMessage()]);
    }

    public function onParticipantStarted(string $name, string $correlationId, string $participantId, TccPhase $phase): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $participantId, $phase]);
    }

    public function onParticipantSuccess(string $name, string $correlationId, string $participantId, TccPhase $phase): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $participantId, $phase]);
    }

    public function onParticipantFailed(string $name, string $correlationId, string $participantId, TccPhase $phase, Throwable $error): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $participantId, $phase, $error->getMessage()]);
    }

    public function onWorkflowSuspended(string $name, string $correlationId, string $reason): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $reason]);
    }

    public function onWorkflowResumed(string $name, string $correlationId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId]);
    }

    public function onCompositionStarted(string $compositionName, string $correlationId): void
    {
        $this->record(__FUNCTION__, [$compositionName, $correlationId]);
    }

    public function onCompositionCompleted(string $compositionName, string $correlationId, bool $success): void
    {
        $this->record(__FUNCTION__, [$compositionName, $correlationId, $success]);
    }

    public function onSignalDelivered(string $name, string $correlationId, string $signalName): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $signalName]);
    }

    public function onTimerFired(string $name, string $correlationId, string $timerId): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $timerId]);
    }

    public function onChildWorkflowStarted(string $parentName, string $parentCorrelationId, string $childWorkflowId, string $childCorrelationId): void
    {
        $this->record(__FUNCTION__, [$parentName, $parentCorrelationId, $childWorkflowId, $childCorrelationId]);
    }

    public function onChildWorkflowCompleted(string $parentName, string $parentCorrelationId, string $childCorrelationId, bool $success): void
    {
        $this->record(__FUNCTION__, [$parentName, $parentCorrelationId, $childCorrelationId, $success]);
    }

    public function onContinueAsNew(string $name, string $oldCorrelationId, string $newCorrelationId): void
    {
        $this->record(__FUNCTION__, [$name, $oldCorrelationId, $newCorrelationId]);
    }

    public function onDeadLettered(string $name, string $correlationId, ?string $stepId, string $error): void
    {
        $this->record(__FUNCTION__, [$name, $correlationId, $stepId, $error]);
    }
}
