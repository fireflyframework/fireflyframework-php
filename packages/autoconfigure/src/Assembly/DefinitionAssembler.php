<?php

declare(strict_types=1);

namespace Firefly\AutoConfigure\Assembly;

use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Context\Scanner\ContextManifest;

/**
 * The joiner M4 deferred (see UserConfigurationsPass / FlushDefinitionsPass "accept pre-scanned data").
 * Consumes M2's ComponentManifest + M4's ContextManifest and produces M4's list<BeanDefinition>. Pure
 * array/object work — ZERO reflection: class-level and per-#[Bean]-method #[ConditionalOn*] instances are
 * rebuilt by ContextDescriptor via `new $type(...$args)`, never reflection. Lives HERE (not in the frozen
 * firefly/context) and feeds BOTH the user path (source = User, gated by ConditionPassOne) and the
 * auto-config path (source = AutoConfiguration, gated incrementally by ConditionPassTwo) via the one
 * $source argument.
 */
final class DefinitionAssembler
{
    /**
     * @return list<BeanDefinition>
     */
    public function assemble(ComponentManifest $components, ContextManifest $context, DefinitionSource $source): array
    {
        $definitions = [];

        foreach ($components->components as $component) {
            $descriptor = $context->forClass($component->class);

            $classConditions = $descriptor?->conditionInstances() ?? [];

            $beanConditions = [];
            foreach ($component->beans as $bean) {
                $methodConditions = $descriptor?->beanConditionInstances($bean->method) ?? [];
                if ($methodConditions !== []) {
                    $beanConditions[$bean->method] = $methodConditions;
                }
            }

            $definitions[] = new BeanDefinition($component, $classConditions, $source, $beanConditions);
        }

        return $definitions;
    }
}
