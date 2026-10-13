<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use DateTimeImmutable;
use Firefly\Orchestration\Core\Callback\CallbackInvoker;
use Firefly\Orchestration\Dlq\DeadLetterEntry;
use Firefly\Orchestration\Dlq\DeadLetterService;
use Firefly\Orchestration\Event\OrchestrationEvent;
use Firefly\Orchestration\Event\OrchestrationEventPublisher;
use Firefly\Orchestration\Event\OrchestrationEvents;
use Firefly\Orchestration\Event\OrchestrationTracing;
use Firefly\Orchestration\Persistence\ExecutionPersistenceProvider;
use Firefly\Orchestration\Persistence\ExecutionState;
use Firefly\Orchestration\Report\ExecutionReport;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The collaborators the three engines share, in one bean so each engine's constructor names only what is its
 * own. `lifecycle` publishes the EXECUTION_* and STEP_* events (a no-op unless firefly.orchestration.events.enabled);
 * `stepEvents` is always EDA-backed, because #[StepEvent], #[TccEvent] and outputEventType are explicit
 * per-step opt-ins (spec decision D9).
 *
 * checkpoint() never throws (spec decision D19): a store that is down mid-run is logged and the run continues,
 * as in jafly and PyFly — the business steps already happened, and refusing to record that is no safer than
 * recording it later.
 */
final class EngineSupport
{
    public function __construct(
        public readonly ExecutionPersistenceProvider $persistence,
        public readonly DeadLetterService $deadLetters,
        public readonly OrchestrationEvents $events,
        public readonly OrchestrationEventPublisher $lifecycle,
        public readonly OrchestrationEventPublisher $stepEvents,
        public readonly OrchestrationTracing $tracing,
        public readonly CallbackInvoker $callbacks,
        public readonly Clock $clock,
        public readonly ExecutionLock $lock,
        public readonly ExecutionAdmission $admission,
        public readonly LoggerInterface $logger,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }

    public function checkpoint(ExecutionContext $context, ExecutionStatus $status, ?string $failureReason = null, ?ExecutionReport $report = null): ExecutionState
    {
        $state = $context->toState($status, $this->now(), $failureReason, $report?->toArray());

        try {
            $this->persistence->save($state);
        } catch (Throwable $error) {
            $this->logger->warning("Orchestration [{$context->executionName}] could not be checkpointed as {$status->value}: {$error->getMessage()}", [
                'exception' => $error,
                'correlationId' => $context->correlationId,
            ]);
        }

        return $state;
    }

    public function deadLetter(ExecutionContext $context, ?string $stepId, ExecutionStatus $status, Throwable $error): void
    {
        $headers = $context->headers();
        $retries = isset($headers[DeadLetterEntry::RETRY_COUNT_HEADER]) ? (int) $headers[DeadLetterEntry::RETRY_COUNT_HEADER] + 1 : 0;
        unset($headers[DeadLetterEntry::RETRY_COUNT_HEADER], $headers[DeadLetterEntry::RETRY_OF_HEADER]);

        try {
            $this->deadLetters->deadLetter(DeadLetterEntry::create(
                $context->executionName, $context->correlationId, $context->pattern, $stepId, $status, $error,
                $context->input(), $headers, $this->now(), $retries,
            ));
        } catch (Throwable $failure) {
            $this->logger->warning("Orchestration [{$context->executionName}] could not be dead-lettered: {$failure->getMessage()}", [
                'exception' => $failure,
                'correlationId' => $context->correlationId,
            ]);
        }
    }

    /**
     * Publishes an event without letting the transport fail the execution it describes: a broker that is down
     * costs a log line, not a compensated saga.
     */
    public function publish(OrchestrationEventPublisher $publisher, OrchestrationEvent $event): void
    {
        try {
            $publisher->publish($event);
        } catch (Throwable $error) {
            $this->logger->warning("Orchestration event {$event->eventType} for [{$event->executionName}] could not be published: {$error->getMessage()}", [
                'exception' => $error,
                'correlationId' => $event->correlationId,
            ]);
        }
    }

    public function elapsedMs(ExecutionContext $context): int
    {
        return max(0, (int) round(((float) $this->now()->format('U.u') - (float) $context->startedAt()->format('U.u')) * 1000));
    }
}
