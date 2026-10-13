<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/**
 * Something a step, compensation, participant phase or query runs: a bean method compiled from attributes
 * (MethodInvocation, pure data, lives in the manifest) or a closure from a builder (ClosureInvocation, never
 * compiled). setVariable() is #[SetVariable]'s target name, or null.
 */
interface Invocation
{
    public function describe(): string;

    public function setVariable(): ?string;

    /** @return array<string, mixed> */
    public function toArray(): array;
}
