<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

use Firefly\Orchestration\Saga\Composition\DataMapping;

/** The TCC composition DSL (jafly TccCompositionBuilder). */
final class TccCompositionBuilder
{
    /** @var array<string, TccCompositionEntry> */
    private array $members = [];

    /** @var list<DataMapping> */
    private array $dataMappings = [];

    private function __construct(private readonly string $name) {}

    public static function composition(string $name): self
    {
        return new self($name);
    }

    public function tcc(string $alias): TccCompositionEntryBuilder
    {
        return new TccCompositionEntryBuilder($this, $alias);
    }

    public function dataFlow(string $source, string $sourceField, string $target, string $targetField): self
    {
        $this->dataMappings[] = new DataMapping($source, $sourceField, $target, $targetField);

        return $this;
    }

    /** @internal called by TccCompositionEntryBuilder::add() */
    public function addMember(TccCompositionEntry $member): self
    {
        $this->members[$member->alias] = $member;

        return $this;
    }

    public function build(): TccComposition
    {
        return new TccComposition($this->name, $this->members, $this->dataMappings);
    }
}
