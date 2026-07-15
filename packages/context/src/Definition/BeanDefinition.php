<?php

declare(strict_types=1);

namespace Firefly\Context\Definition;

use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Context\Condition\ConditionAttribute;

/**
 * A bean definition awaiting condition evaluation during the boot engine's definition stage.
 *
 * WRAPS a ComponentDescriptor rather than re-declaring its fields: re-declaring the 9 descriptor
 * fields here would make the conversion back to a ComponentManifest hand-written and guaranteed to
 * drift the first time either side changes a field. Wrapping keeps
 * BeanDefinitionRegistry::toComponentManifest() a trivially lossless array_map.
 */
final class BeanDefinition
{
    /**
     * @param  list<ConditionAttribute>  $conditions
     */
    public function __construct(
        public readonly ComponentDescriptor $descriptor,
        public readonly array $conditions = [],
        public readonly DefinitionSource $source = DefinitionSource::User,
    ) {}

    public function class(): string
    {
        return $this->descriptor->class;
    }
}
