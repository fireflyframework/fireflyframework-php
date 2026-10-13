<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Composition;

use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\Topology\TopologyBuilder;
use Firefly\Orchestration\Saga\Composition\DataMapping;

/** A transaction of TCC transactions (jafly TccComposition), layered and validated like a saga composition. */
final readonly class TccComposition
{
    /** @var list<list<string>> */
    public array $layers;

    /**
     * @param  array<string, TccCompositionEntry>  $members
     * @param  list<DataMapping>  $dataMappings
     */
    public function __construct(
        public string $name,
        public array $members,
        public array $dataMappings = [],
    ) {
        $dependencies = [];
        foreach ($members as $alias => $member) {
            $dependencies[(string) $alias] = $member->dependsOn;
        }
        $this->layers = TopologyBuilder::layers("TCC composition [{$name}]", $dependencies);

        foreach ($dataMappings as $mapping) {
            foreach ([$mapping->sourceSaga, $mapping->targetSaga] as $alias) {
                if (! isset($members[$alias])) {
                    throw new TopologyValidationException("TCC composition [{$name}] maps data ".($alias === $mapping->sourceSaga ? 'from' : 'to')." [{$alias}], which is not a member of it.");
                }
            }
        }
    }
}
