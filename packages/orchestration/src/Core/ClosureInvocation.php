<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

use Closure;
use LogicException;

/**
 * A builder-defined step: `fn (mixed $input, ExecutionContext $ctx) => …`. It lives only in the process that
 * built it, so it can never be compiled into a manifest — and a builder-defined saga can be resumed after a
 * crash only by a process that registered the same definition again.
 */
final readonly class ClosureInvocation implements Invocation
{
    public function __construct(
        public Closure $closure,
        public string $label = 'closure',
        public ?string $variable = null,
    ) {}

    public function describe(): string
    {
        return $this->label;
    }

    public function setVariable(): ?string
    {
        return $this->variable;
    }

    public function toArray(): array
    {
        throw new LogicException("Invocation [{$this->label}] was built from a closure and cannot be compiled into a manifest.");
    }
}
