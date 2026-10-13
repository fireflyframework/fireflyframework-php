<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Engine;

use Closure;
use Firefly\Orchestration\Core\ExecutionContext;

/**
 * What each saga step receives as input (jafly StepInputs): an explicit value for a step, else a resolver
 * computed from the context the first time the step needs it (memoized), else the execution's global input —
 * which is what an EDA trigger, a scheduled start or a REST call provides.
 */
final class StepInputs
{
    /** @var array<string, mixed> */
    private array $resolved = [];

    /**
     * @param  array<string, mixed>  $global
     * @param  array<string, mixed>  $values
     * @param  array<string, Closure(ExecutionContext): mixed>  $resolvers
     */
    private function __construct(
        private readonly array $global,
        private readonly array $values,
        private readonly array $resolvers,
    ) {}

    /**
     * @param  array<string, mixed>  $global
     */
    public static function of(array $global = []): self
    {
        return new self($global, [], []);
    }

    public function forStep(string $stepId, mixed $input): self
    {
        return new self($this->global, [...$this->values, $stepId => $input], $this->resolvers);
    }

    /**
     * @param  Closure(ExecutionContext): mixed  $resolver
     */
    public function forStepResolver(string $stepId, Closure $resolver): self
    {
        return new self($this->global, $this->values, [...$this->resolvers, $stepId => $resolver]);
    }

    public function resolveFor(string $stepId, ExecutionContext $context): mixed
    {
        if (array_key_exists($stepId, $this->values)) {
            return $this->values[$stepId];
        }

        if (isset($this->resolvers[$stepId])) {
            if (! array_key_exists($stepId, $this->resolved)) {
                $this->resolved[$stepId] = ($this->resolvers[$stepId])($context);
            }

            return $this->resolved[$stepId];
        }

        return $this->global;
    }

    /** @return array<string, mixed> */
    public function global(): array
    {
        return $this->global;
    }

    /** @return array<string, mixed> */
    public function explicit(): array
    {
        return $this->values;
    }
}
