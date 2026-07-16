<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Pass;

use Firefly\AutoConfigure\AutoConfigurationCollector;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;

/**
 * Phase 500 (AutoConfigurations). Drains the AutoConfigDiscoveryPass-assembled definitions from the collector
 * into the BeanDefinitionRegistry — the SOLE registry write for auto-config definitions (BootPhase enum line
 * 56). Every added definition is FORCED to DefinitionSource::AutoConfiguration (exactly as UserConfigurationsPass
 * forces DefinitionSource::User), so ConditionPassTwoPass (phase 600) picks them up for incremental
 * (order, FQCN) evaluation and #[ConditionalOnMissingBean] back-off against the already-filtered user beans.
 */
final class AutoConfigurationsPass implements BootPass
{
    public function __construct(private readonly AutoConfigurationCollector $collector) {}

    public function phase(): BootPhase
    {
        return BootPhase::AutoConfigurations;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        foreach ($this->collector->assembledDefinitions() as $definition) {
            $context->definitions->add(new BeanDefinition(
                $definition->descriptor,
                $definition->conditions,
                DefinitionSource::AutoConfiguration,
                $definition->beanConditions,
            ));
        }
    }
}
