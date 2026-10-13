<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\TccPhase;
use Throwable;

/**
 * In-process lifecycle hooks (jafly OrchestrationEvents parity), called synchronously by the three engines.
 * Implement it — usually by extending OrchestrationEventsAdapter and overriding what you need — on a
 * #[Component] and CompositeOrchestrationEvents picks it up beside the shipped logger and metrics hooks. A hook
 * that throws is logged and never alters the execution it observes.
 */
interface OrchestrationEvents
{
    public function onStart(string $name, string $correlationId, ExecutionPattern $pattern): void;

    public function onStepStarted(string $name, string $correlationId, string $stepId): void;

    public function onStepSuccess(string $name, string $correlationId, string $stepId, int $attempts, int $latencyMs): void;

    public function onStepFailed(string $name, string $correlationId, string $stepId, Throwable $error, int $attempts): void;

    public function onStepSkipped(string $name, string $correlationId, string $stepId): void;

    public function onStepSkippedIdempotent(string $name, string $correlationId, string $stepId): void;

    public function onCompleted(string $name, string $correlationId, ExecutionPattern $pattern, bool $success, int $durationMs): void;

    public function onCompensationStarted(string $name, string $correlationId): void;

    public function onStepCompensated(string $name, string $correlationId, string $stepId): void;

    public function onStepCompensationFailed(string $name, string $correlationId, string $stepId, Throwable $error): void;

    public function onPhaseStarted(string $name, string $correlationId, TccPhase $phase): void;

    public function onPhaseCompleted(string $name, string $correlationId, TccPhase $phase, int $durationMs): void;

    public function onPhaseFailed(string $name, string $correlationId, TccPhase $phase, ?Throwable $error): void;

    public function onParticipantStarted(string $name, string $correlationId, string $participantId, TccPhase $phase): void;

    public function onParticipantSuccess(string $name, string $correlationId, string $participantId, TccPhase $phase): void;

    public function onParticipantFailed(string $name, string $correlationId, string $participantId, TccPhase $phase, Throwable $error): void;

    public function onWorkflowSuspended(string $name, string $correlationId, string $reason): void;

    public function onWorkflowResumed(string $name, string $correlationId): void;

    public function onCompositionStarted(string $compositionName, string $correlationId): void;

    public function onCompositionCompleted(string $compositionName, string $correlationId, bool $success): void;

    public function onSignalDelivered(string $name, string $correlationId, string $signalName): void;

    public function onTimerFired(string $name, string $correlationId, string $timerId): void;

    public function onChildWorkflowStarted(string $parentName, string $parentCorrelationId, string $childWorkflowId, string $childCorrelationId): void;

    public function onChildWorkflowCompleted(string $parentName, string $parentCorrelationId, string $childCorrelationId, bool $success): void;

    public function onContinueAsNew(string $name, string $oldCorrelationId, string $newCorrelationId): void;

    public function onDeadLettered(string $name, string $correlationId, ?string $stepId, string $error): void;
}
