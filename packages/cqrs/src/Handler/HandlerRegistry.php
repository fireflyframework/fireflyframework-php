<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Handler;

use Firefly\Cqrs\Exception\CommandHandlerNotFoundException;
use Firefly\Cqrs\Exception\CqrsConfigurationException;
use Firefly\Cqrs\Exception\QueryHandlerNotFoundException;

/**
 * The pure in-process message-class -> invoker map the buses dispatch through (pyfly command/registry.py parity).
 * Two independent maps (command, query) keyed by message class-string; each value is a `callable(object): mixed`
 * invoker the CqrsHandlerWiringPass installs — one that resolves the handler bean FRESH per dispatch (never cached),
 * so a #[Transactional] handler is always observed through its M8 proxy. No reflection: keys come from the compiled
 * manifest. One command -> one handler: a duplicate registration for the same message class within a kind fails loud
 * at wiring time (pyfly only warns; LaraFly aborts). A container singleton (request-scoped under share-nothing PHP-FPM;
 * rebuilt per worker boot under Octane by the wiring pass).
 *
 * @phpstan-type Invoker callable(object): mixed
 */
final class HandlerRegistry
{
    /** @var array<string, callable(object): mixed> */
    private array $commandHandlers = [];

    /** @var array<string, callable(object): mixed> */
    private array $queryHandlers = [];

    /**
     * @param  callable(object): mixed  $invoker
     */
    public function registerCommandHandler(string $commandClass, callable $invoker): void
    {
        if (isset($this->commandHandlers[$commandClass])) {
            throw new CqrsConfigurationException("Duplicate command handler registered for message [{$commandClass}]. One command maps to exactly one handler.");
        }

        $this->commandHandlers[$commandClass] = $invoker;
    }

    /**
     * @param  callable(object): mixed  $invoker
     */
    public function registerQueryHandler(string $queryClass, callable $invoker): void
    {
        if (isset($this->queryHandlers[$queryClass])) {
            throw new CqrsConfigurationException("Duplicate query handler registered for message [{$queryClass}]. One query maps to exactly one handler.");
        }

        $this->queryHandlers[$queryClass] = $invoker;
    }

    /**
     * @return callable(object): mixed
     */
    public function findCommandHandler(string $commandClass): callable
    {
        return $this->commandHandlers[$commandClass] ?? throw new CommandHandlerNotFoundException($commandClass);
    }

    /**
     * @return callable(object): mixed
     */
    public function findQueryHandler(string $queryClass): callable
    {
        return $this->queryHandlers[$queryClass] ?? throw new QueryHandlerNotFoundException($queryClass);
    }

    public function hasCommandHandler(string $commandClass): bool
    {
        return isset($this->commandHandlers[$commandClass]);
    }

    public function hasQueryHandler(string $queryClass): bool
    {
        return isset($this->queryHandlers[$queryClass]);
    }
}
