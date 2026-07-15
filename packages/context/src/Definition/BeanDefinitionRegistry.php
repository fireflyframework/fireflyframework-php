<?php

declare(strict_types=1);

namespace Firefly\Context\Definition;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scanner\ComponentManifest;

/**
 * Pure in-memory storage for BeanDefinitions collected during the boot engine's definition stage
 * (BootPhase::ConditionPassOne through BootPhase::FlushDefinitions).
 *
 * MUST NOT touch the container — the definition stage and the instance stage are deliberately
 * split so that exactly one condition-filtered ComponentManifest is ever handed to the container
 * registrar (FlushDefinitionsPass).
 */
final class BeanDefinitionRegistry
{
    /** @var list<BeanDefinition> */
    private array $definitions = [];

    public function add(BeanDefinition $definition): void
    {
        $this->definitions[] = $definition;
    }

    public function remove(string $class): void
    {
        $this->definitions = array_values(array_filter(
            $this->definitions,
            static fn (BeanDefinition $definition): bool => $definition->class() !== $class,
        ));
    }

    /**
     * @return list<BeanDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * True if any definition makes $type available: as its own class, as a declared interface, or
     * as the return type of a #[Bean] method. All three are ways a type becomes resolvable —
     * missing any of them would make #[ConditionalOnBean] silently wrong.
     */
    public function containsType(string $type): bool
    {
        foreach ($this->definitions as $definition) {
            if ($definition->class() === $type) {
                return true;
            }

            if (in_array($type, $definition->descriptor->interfaces, true)) {
                return true;
            }

            foreach ($definition->descriptor->beans as $bean) {
                if ($bean->returns === $type) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Builds a NEW ComponentManifest from the current definitions. Never mutates M2's manifest —
     * this is the single condition-filtered manifest FlushDefinitionsPass hands to
     * ContainerRegistrar::register().
     */
    public function toComponentManifest(): ComponentManifest
    {
        return new ComponentManifest(array_map(
            static fn (BeanDefinition $definition): ComponentDescriptor => $definition->descriptor,
            $this->definitions,
        ));
    }
}
