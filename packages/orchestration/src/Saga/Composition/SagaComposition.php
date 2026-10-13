<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Composition;

use Firefly\Orchestration\Core\Exception\TopologyValidationException;
use Firefly\Orchestration\Core\Topology\TopologyBuilder;

/**
 * A saga of sagas (jafly SagaComposition): members layered by dependsOn exactly like steps, data flowing from
 * earlier members' results into later members' input. Validated at construction — cycles, unknown dependsOn,
 * data mappings that name no member.
 */
final readonly class SagaComposition
{
    /** @var list<list<string>> */
    public array $layers;

    /**
     * @param  array<string, CompositionSaga>  $sagas
     * @param  list<DataMapping>  $dataMappings
     */
    public function __construct(
        public string $name,
        public array $sagas,
        public array $dataMappings = [],
    ) {
        $dependencies = [];
        foreach ($sagas as $alias => $member) {
            $dependencies[(string) $alias] = $member->dependsOn;
        }
        $this->layers = TopologyBuilder::layers("Saga composition [{$name}]", $dependencies);

        foreach ($dataMappings as $mapping) {
            foreach ([$mapping->sourceSaga, $mapping->targetSaga] as $alias) {
                if (! isset($sagas[$alias])) {
                    throw new TopologyValidationException("Saga composition [{$name}] maps data ".($alias === $mapping->sourceSaga ? 'from' : 'to')." [{$alias}], which is not a member of it.");
                }
            }
        }
    }
}
