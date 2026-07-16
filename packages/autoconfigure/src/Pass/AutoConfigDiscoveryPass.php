<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Pass;

use Firefly\AutoConfigure\Assembly\DefinitionAssembler;
use Firefly\AutoConfigure\AutoConfigurationCandidate;
use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Scanner\ContextManifest;

/**
 * Phase 200 (AutoConfigDiscovery). DISCOVER + ASSEMBLE ONLY — it NEVER writes BeanDefinitionRegistry (the
 * BootPhase enum's own line-53 mandate). For every candidate in the container-bound collector it LOADS the
 * candidate's compiled manifests (zero reflection) and assembles list<BeanDefinition> tagged
 * DefinitionSource::AutoConfiguration, concatenating them and stashing the result back in the collector for
 * AutoConfigurationsPass (phase 500) to drain. Candidates are ordered by provider FQCN here purely for a
 * reproducible roster; the load-bearing precedence is ConditionPassTwoPass's (order, FQCN) sort at phase 600.
 */
final class AutoConfigDiscoveryPass implements BootPass
{
    public function __construct(
        private readonly AutoConfigurationCollector $collector,
        private readonly DefinitionAssembler $assembler,
    ) {}

    public function phase(): BootPhase
    {
        return BootPhase::AutoConfigDiscovery;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        $candidates = $this->collector->all();
        usort(
            $candidates,
            static fn (AutoConfigurationCandidate $a, AutoConfigurationCandidate $b): int => $a->provider <=> $b->provider,
        );

        $definitions = [];
        foreach ($candidates as $candidate) {
            $components = ComponentManifest::load($candidate->componentManifestPath);
            $manifest = ContextManifest::load($candidate->contextManifestPath);

            foreach ($this->assembler->assemble($components, $manifest, DefinitionSource::AutoConfiguration) as $definition) {
                $definitions[] = $definition;
            }
        }

        $this->collector->setAssembledDefinitions($definitions);
    }
}
