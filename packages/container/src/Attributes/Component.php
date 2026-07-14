<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;
use Firefly\Container\Scope;

/**
 * Base stereotype. #[Service], #[Repository], #[Configuration] specialise it; a component scan finds
 * them all via ReflectionClass::getAttributes(Component::class, ReflectionAttribute::IS_INSTANCEOF).
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Component
{
    public function __construct(
        public ?string $name = null,
        public Scope $scope = Scope::Singleton,
    ) {}
}
