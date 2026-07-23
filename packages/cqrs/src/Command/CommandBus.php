<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Command;

/**
 * The command dispatch PORT: send a command through the bounded pipeline (correlate -> validate -> authorize ->
 * resolve+invoke handler -> metrics) to its single registered handler, returning whatever the handler returns.
 * `send` is the write-side verb (pyfly parity). DefaultCommandBus is the shipped implementation.
 */
interface CommandBus
{
    public function send(object $command): mixed;
}
