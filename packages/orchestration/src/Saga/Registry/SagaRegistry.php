<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Registry;

use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;

/**
 * The sagas this application can run: the compiled ones (OrchestrationRegistryPass registers them at boot)
 * plus any a provider registers from a SagaBuilder. A name is defined once; a builder saga that should survive
 * a crash must be registered in every process, because recovery looks it up here by name.
 */
final class SagaRegistry
{
    /** @var array<string, SagaDefinition> */
    private array $sagas = [];

    public function register(SagaDefinition $saga): void
    {
        if (isset($this->sagas[$saga->name])) {
            throw new TopologyValidationException("Saga [{$saga->name}] is defined twice.");
        }

        $this->sagas[$saga->name] = $saga;
    }

    public function get(string $name): SagaDefinition
    {
        return $this->sagas[$name] ?? throw new DefinitionNotFoundException("No saga [{$name}] is registered.");
    }

    public function has(string $name): bool
    {
        return isset($this->sagas[$name]);
    }

    /** @return array<string, SagaDefinition> */
    public function all(): array
    {
        return $this->sagas;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->sagas));
    }
}
