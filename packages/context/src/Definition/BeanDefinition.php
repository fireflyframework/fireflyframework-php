<?php

declare(strict_types=1);

namespace Firefly\Context\Definition;

use Firefly\Container\Descriptor\BeanDescriptor;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Context\Condition\ConditionAttribute;

/**
 * A bean definition awaiting condition evaluation during the boot engine's definition stage.
 *
 * WRAPS a ComponentDescriptor rather than re-declaring its fields: re-declaring the 9 descriptor
 * fields here would make the conversion back to a ComponentManifest hand-written and guaranteed to
 * drift the first time either side changes a field. Wrapping keeps
 * BeanDefinitionRegistry::toComponentManifest() a trivially lossless array_map.
 *
 * $conditions gates the WHOLE definition (gate the class/#[Configuration] itself); $beanConditions
 * gates ONE #[Bean] method at a time, keyed by method name. The two are DELIBERATELY separate lists
 * rather than one flat list: a class-level condition failing removes the entire definition (every
 * #[Bean] method on it included), while a method-level condition failing must remove only that ONE
 * #[Bean] method from the descriptor's `beans` list, leaving the definition (and every other
 * #[Bean] method on it) in place. Collapsing both into one flat list — as an earlier version of
 * this class did — left nowhere to carry the second kind, which is exactly why method-level
 * #[ConditionalOn*] on a #[Bean] method was scanned, stored, and documented, yet silently never
 * consumed: see ConditionPassOnePass/ConditionPassTwoPass, which now filter `beans` accordingly, and
 * BeanDefinition::withoutBeanMethods() below, which performs that filtering.
 *
 * This concept is deliberately kept OUT of firefly/container's BeanDescriptor: BeanDescriptor is
 * M2's plain "what does this #[Bean] method return" fact, with no notion of conditions —
 * introducing one there would invert the layering (firefly/container must not know about
 * firefly/context's conditions). BeanDefinition, which already exists purely to carry M4-specific,
 * condition-stage-only state alongside an M2 descriptor, is the correct home instead.
 */
final class BeanDefinition
{
    /**
     * @param  list<ConditionAttribute>  $conditions
     * @param  array<string, list<ConditionAttribute>>  $beanConditions  keyed by #[Bean] method name
     */
    public function __construct(
        public readonly ComponentDescriptor $descriptor,
        public readonly array $conditions = [],
        public readonly DefinitionSource $source = DefinitionSource::User,
        public readonly array $beanConditions = [],
    ) {}

    public function class(): string
    {
        return $this->descriptor->class;
    }

    /**
     * Returns a new BeanDefinition with the named #[Bean] method(s) removed from the wrapped
     * ComponentDescriptor's `beans` list — used by the condition passes to gate ONE #[Bean] method's
     * registration without removing the whole definition. ComponentDescriptor is `final readonly`,
     * so this REBUILDS it rather than mutating; every other field is carried over unchanged.
     * Returns $this UNCHANGED (same instance, not merely an equal copy) when $methods is empty, so a
     * caller can cheaply test `$filtered !== $definition` to decide whether anything actually needs
     * to be swapped back into the registry.
     *
     * @param  list<string>  $methods
     */
    public function withoutBeanMethods(array $methods): self
    {
        if ($methods === []) {
            return $this;
        }

        $descriptor = $this->descriptor;

        $descriptor = new ComponentDescriptor(
            class: $descriptor->class,
            stereotype: $descriptor->stereotype,
            name: $descriptor->name,
            scope: $descriptor->scope,
            primary: $descriptor->primary,
            order: $descriptor->order,
            qualifier: $descriptor->qualifier,
            interfaces: $descriptor->interfaces,
            beans: array_values(array_filter(
                $descriptor->beans,
                static fn (BeanDescriptor $bean): bool => ! in_array($bean->method, $methods, true),
            )),
            lazy: $descriptor->lazy,
        );

        return new self($descriptor, $this->conditions, $this->source, $this->beanConditions);
    }
}
