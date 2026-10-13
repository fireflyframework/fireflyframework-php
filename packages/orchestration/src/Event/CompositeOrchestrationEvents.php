<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Event;

use Closure;
use Firefly\Orchestration\Core\ExecutionPattern;
use Firefly\Orchestration\Core\TccPhase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * The OrchestrationEvents the engines actually call: the shipped listeners (logger, metrics) plus every
 * application #[Component] implementing the interface, discovered LAZILY on the first hook. Lazily, because
 * the composite is itself a bean of that interface: resolving the tagged implementations while building it
 * would ask the container for the composite again. By the first hook the singleton exists, the lookup returns
 * it like any other, and it is filtered out.
 */
final class CompositeOrchestrationEvents implements OrchestrationEvents
{
    /** @var list<OrchestrationEvents>|null */
    private ?array $resolved = null;

    private readonly LoggerInterface $logger;

    /**
     * @param  list<OrchestrationEvents>  $listeners
     * @param  (Closure(): iterable<mixed>)|null  $discover
     */
    public function __construct(
        private readonly array $listeners = [],
        private readonly ?Closure $discover = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger;
    }

    /** @return list<OrchestrationEvents> */
    public function listeners(): array
    {
        if ($this->resolved === null) {
            $found = $this->listeners;
            foreach ($this->discover === null ? [] : ($this->discover)() as $listener) {
                if ($listener instanceof OrchestrationEvents && ! $listener instanceof self && ! in_array($listener, $found, true)) {
                    $found[] = $listener;
                }
            }
            $this->resolved = $found;
        }

        return $this->resolved;
    }

    /**
     * @param  Closure(OrchestrationEvents): void  $call
     */
    private function each(string $hook, Closure $call): void
    {
        foreach ($this->listeners() as $listener) {
            try {
                $call($listener);
            } catch (Throwable $error) {
                $this->logger->warning("Orchestration hook {$hook} on ".$listener::class." failed: {$error->getMessage()}", ['exception' => $error]);
            }
        }
    }

    public function onStart(string $name, string $correlationId, ExecutionPattern $pattern): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $pattern): void {
            $listener->onStart($name, $correlationId, $pattern);
        });
    }

    public function onStepStarted(string $name, string $correlationId, string $stepId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId): void {
            $listener->onStepStarted($name, $correlationId, $stepId);
        });
    }

    public function onStepSuccess(string $name, string $correlationId, string $stepId, int $attempts, int $latencyMs): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId, $attempts, $latencyMs): void {
            $listener->onStepSuccess($name, $correlationId, $stepId, $attempts, $latencyMs);
        });
    }

    public function onStepFailed(string $name, string $correlationId, string $stepId, Throwable $error, int $attempts): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId, $error, $attempts): void {
            $listener->onStepFailed($name, $correlationId, $stepId, $error, $attempts);
        });
    }

    public function onStepSkipped(string $name, string $correlationId, string $stepId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId): void {
            $listener->onStepSkipped($name, $correlationId, $stepId);
        });
    }

    public function onStepSkippedIdempotent(string $name, string $correlationId, string $stepId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId): void {
            $listener->onStepSkippedIdempotent($name, $correlationId, $stepId);
        });
    }

    public function onCompleted(string $name, string $correlationId, ExecutionPattern $pattern, bool $success, int $durationMs): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $pattern, $success, $durationMs): void {
            $listener->onCompleted($name, $correlationId, $pattern, $success, $durationMs);
        });
    }

    public function onCompensationStarted(string $name, string $correlationId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId): void {
            $listener->onCompensationStarted($name, $correlationId);
        });
    }

    public function onStepCompensated(string $name, string $correlationId, string $stepId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId): void {
            $listener->onStepCompensated($name, $correlationId, $stepId);
        });
    }

    public function onStepCompensationFailed(string $name, string $correlationId, string $stepId, Throwable $error): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId, $error): void {
            $listener->onStepCompensationFailed($name, $correlationId, $stepId, $error);
        });
    }

    public function onPhaseStarted(string $name, string $correlationId, TccPhase $phase): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $phase): void {
            $listener->onPhaseStarted($name, $correlationId, $phase);
        });
    }

    public function onPhaseCompleted(string $name, string $correlationId, TccPhase $phase, int $durationMs): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $phase, $durationMs): void {
            $listener->onPhaseCompleted($name, $correlationId, $phase, $durationMs);
        });
    }

    public function onPhaseFailed(string $name, string $correlationId, TccPhase $phase, ?Throwable $error): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $phase, $error): void {
            $listener->onPhaseFailed($name, $correlationId, $phase, $error);
        });
    }

    public function onParticipantStarted(string $name, string $correlationId, string $participantId, TccPhase $phase): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $participantId, $phase): void {
            $listener->onParticipantStarted($name, $correlationId, $participantId, $phase);
        });
    }

    public function onParticipantSuccess(string $name, string $correlationId, string $participantId, TccPhase $phase): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $participantId, $phase): void {
            $listener->onParticipantSuccess($name, $correlationId, $participantId, $phase);
        });
    }

    public function onParticipantFailed(string $name, string $correlationId, string $participantId, TccPhase $phase, Throwable $error): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $participantId, $phase, $error): void {
            $listener->onParticipantFailed($name, $correlationId, $participantId, $phase, $error);
        });
    }

    public function onWorkflowSuspended(string $name, string $correlationId, string $reason): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $reason): void {
            $listener->onWorkflowSuspended($name, $correlationId, $reason);
        });
    }

    public function onWorkflowResumed(string $name, string $correlationId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId): void {
            $listener->onWorkflowResumed($name, $correlationId);
        });
    }

    public function onCompositionStarted(string $compositionName, string $correlationId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($compositionName, $correlationId): void {
            $listener->onCompositionStarted($compositionName, $correlationId);
        });
    }

    public function onCompositionCompleted(string $compositionName, string $correlationId, bool $success): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($compositionName, $correlationId, $success): void {
            $listener->onCompositionCompleted($compositionName, $correlationId, $success);
        });
    }

    public function onSignalDelivered(string $name, string $correlationId, string $signalName): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $signalName): void {
            $listener->onSignalDelivered($name, $correlationId, $signalName);
        });
    }

    public function onTimerFired(string $name, string $correlationId, string $timerId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $timerId): void {
            $listener->onTimerFired($name, $correlationId, $timerId);
        });
    }

    public function onChildWorkflowStarted(string $parentName, string $parentCorrelationId, string $childWorkflowId, string $childCorrelationId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($parentName, $parentCorrelationId, $childWorkflowId, $childCorrelationId): void {
            $listener->onChildWorkflowStarted($parentName, $parentCorrelationId, $childWorkflowId, $childCorrelationId);
        });
    }

    public function onChildWorkflowCompleted(string $parentName, string $parentCorrelationId, string $childCorrelationId, bool $success): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($parentName, $parentCorrelationId, $childCorrelationId, $success): void {
            $listener->onChildWorkflowCompleted($parentName, $parentCorrelationId, $childCorrelationId, $success);
        });
    }

    public function onContinueAsNew(string $name, string $oldCorrelationId, string $newCorrelationId): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $oldCorrelationId, $newCorrelationId): void {
            $listener->onContinueAsNew($name, $oldCorrelationId, $newCorrelationId);
        });
    }

    public function onDeadLettered(string $name, string $correlationId, ?string $stepId, string $error): void
    {
        $this->each(__FUNCTION__, static function (OrchestrationEvents $listener) use ($name, $correlationId, $stepId, $error): void {
            $listener->onDeadLettered($name, $correlationId, $stepId, $error);
        });
    }
}
