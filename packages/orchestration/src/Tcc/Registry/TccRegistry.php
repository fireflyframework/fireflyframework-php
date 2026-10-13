<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Registry;

use Firefly\Orchestration\Core\Exception\DefinitionNotFoundException;
use Firefly\Orchestration\Core\Exception\TopologyValidationException;

/** The TCC transactions this application can run; a name is defined once. */
final class TccRegistry
{
    /** @var array<string, TccDefinition> */
    private array $tccs = [];

    public function register(TccDefinition $tcc): void
    {
        if (isset($this->tccs[$tcc->name])) {
            throw new TopologyValidationException("TCC [{$tcc->name}] is defined twice.");
        }

        $this->tccs[$tcc->name] = $tcc;
    }

    public function get(string $name): TccDefinition
    {
        return $this->tccs[$name] ?? throw new DefinitionNotFoundException("No TCC [{$name}] is registered.");
    }

    public function has(string $name): bool
    {
        return isset($this->tccs[$name]);
    }

    /** @return array<string, TccDefinition> */
    public function all(): array
    {
        return $this->tccs;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map('strval', array_keys($this->tccs));
    }
}
