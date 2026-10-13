<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

/** One member of a SagaCompositionBuilder; the saga name defaults to the alias. */
final class CompositionSagaBuilder
{
    private string $sagaName;

    /** @var list<string> */
    private array $dependsOn = [];

    /** @var array<string, mixed> */
    private array $input = [];

    private bool $optional = false;

    private string $condition = '';

    public function __construct(
        private readonly SagaCompositionBuilder $composition,
        private readonly string $alias,
    ) {
        $this->sagaName = $alias;
    }

    public function sagaName(string $sagaName): self
    {
        $this->sagaName = $sagaName;

        return $this;
    }

    public function dependsOn(string ...$aliases): self
    {
        $this->dependsOn = array_values([...$this->dependsOn, ...$aliases]);

        return $this;
    }

    public function input(string $key, mixed $value): self
    {
        $this->input[$key] = $value;

        return $this;
    }

    public function optional(bool $optional = true): self
    {
        $this->optional = $optional;

        return $this;
    }

    public function condition(string $expression): self
    {
        $this->condition = $expression;

        return $this;
    }

    public function add(): SagaCompositionBuilder
    {
        return $this->composition->addSaga(new CompositionSaga($this->alias, $this->sagaName, $this->dependsOn, $this->input, $this->optional, $this->condition));
    }
}
