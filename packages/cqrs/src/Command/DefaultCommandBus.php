<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Command;

use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Cqrs\Validation\MessageValidator;
use Throwable;

/**
 * The synchronous in-process command mediator. send() runs a bounded pipeline of injected, no-op-by-default
 * collaborators: correlate (begin, restored in finally so no id leaks into the next command) -> validate (over the
 * shipped Validator, opt-in via Validatable) -> authorize (allow-all default, M11 swaps it) -> resolve the single
 * registered handler and invoke it. The handler method is typically #[Transactional], so the M8 proxy wraps it — the
 * bus is oblivious to the transaction. There is deliberately NO inline domain-event loop (pyfly command/bus.py:174-210):
 * domain events flow through M8's after-commit dispatch + the cqrs bridge, keeping the bus pure and avoiding double
 * publish. Any handler/stage throwable is metrics-recorded as a failure and re-thrown WRAPPED in a category-preserving
 * CommandProcessingException (unless it already is one, then re-thrown as-is) so validation/not-found faults keep
 * their kernel category for RFC-7807 rendering.
 */
final class DefaultCommandBus implements CommandBus
{
    public function __construct(
        private readonly HandlerRegistry $registry,
        private readonly MessageValidator $validator,
        private readonly CommandAuthorizer $authorizer,
        private readonly CorrelationContext $correlation,
        private readonly CqrsMetrics $metrics,
    ) {}

    public function send(object $command): mixed
    {
        $prior = $this->correlation->begin();
        $startedAt = microtime(true);

        try {
            $this->validator->validate($command);
            $this->authorizer->authorize($command);

            $handler = $this->registry->findCommandHandler($command::class);
            $result = $handler($command);

            $this->metrics->recordCommandSuccess($command, microtime(true) - $startedAt);

            return $result;
        } catch (Throwable $e) {
            $this->metrics->recordCommandFailure($command, microtime(true) - $startedAt);

            throw $e instanceof CommandProcessingException ? $e : new CommandProcessingException($command::class, $e);
        } finally {
            $this->correlation->end($prior);
        }
    }
}
