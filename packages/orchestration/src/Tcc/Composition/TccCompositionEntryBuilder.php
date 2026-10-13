<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

/** One member of a TccCompositionBuilder; the TCC name defaults to the alias. */
final class TccCompositionEntryBuilder
{
    private string $tccName;

    /** @var list<string> */
    private array $dependsOn = [];

    /** @var array<string, mixed> */
    private array $input = [];

    private bool $optional = false;

    private string $condition = '';

    public function __construct(
        private readonly TccCompositionBuilder $composition,
        private readonly string $alias,
    ) {
        $this->tccName = $alias;
    }

    public function tccName(string $tccName): self
    {
        $this->tccName = $tccName;

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

    public function add(): TccCompositionBuilder
    {
        return $this->composition->addMember(new TccCompositionEntry($this->alias, $this->tccName, $this->dependsOn, $this->input, $this->optional, $this->condition));
    }
}
