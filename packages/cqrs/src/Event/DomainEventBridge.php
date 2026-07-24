<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Event;

use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Domain\DomainEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The in-process trigger's delegate: for each committed DomainEvent, publish it as an integration event through the
 * resolved CommandEventPublisher (NoOp or Eda), applying the failure strategy. Runs INSIDE M8's DB::afterCommit
 * callback — the write already committed, so under LOG (default) a publish failure is swallowed + logged (the command
 * result stands; best-effort, at-least-once-ish — true atomicity is the SP-4 outbox), and under RAISE it is re-thrown
 * wrapped in CommandProcessingException (unless already one). This class is registered as a listener by
 * DomainEventBridgeWiringPass, NOT via #[AsEventListener] (a base-class DomainEvent listener can never match a
 * concrete subclass on Laravel's dispatcher — see the plan header's make-or-break note).
 */
final class DomainEventBridge
{
    public function __construct(
        private readonly CommandEventPublisher $publisher,
        private readonly EventFailureStrategy $failureStrategy = EventFailureStrategy::Log,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function publish(DomainEvent $event): void
    {
        $eventClass = $event::class;

        try {
            $this->publisher->publish($event);
        } catch (Throwable $e) {
            if ($this->failureStrategy === EventFailureStrategy::Raise) {
                throw $e instanceof CommandProcessingException ? $e : new CommandProcessingException($eventClass, $e);
            }

            $this->logger?->error(
                "CQRS domain-event bridge failed to publish [{$eventClass}]: {$e->getMessage()}",
                ['exception' => $e],
            );
        }
    }
}
