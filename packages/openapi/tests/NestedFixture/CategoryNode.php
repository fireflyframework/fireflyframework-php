<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\NestedFixture;

/**
 * A self-referential DTO whose cycle runs through a LIST rather than through a nullable member — the shape
 * that makes an `items` builder recurse forever if the component name is not reserved before the body is
 * built. SchemaRegistry reserves it, so the inner `$ref` closes the cycle on the component being built.
 */
final class CategoryNode
{
    /**
     * @param  list<CategoryNode>  $children  Sub-categories, to any depth.
     */
    public function __construct(
        public readonly string $label,
        public readonly array $children = [],
    ) {}
}
