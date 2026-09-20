<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Attributes;

use Attribute;

/**
 * Eager-loads relations for one repository method — Spring Data's @EntityGraph, mapped to Eloquent's with().
 * Either `attributePaths` (dotted relation paths, `['lines', 'lines.product']`) or `value`, the name of a graph
 * the repository declares in `protected array $entityGraphs = ['Order.full' => [...]]`. Applies to a derived
 * method declared with a dispatchQuery() body, and to an inherited read (findById/findAll/findPaged/...) the
 * repository overrides with `return parent::findAll();` under the attribute.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class EntityGraph
{
    /**
     * @param  list<string>  $attributePaths
     */
    public function __construct(
        public ?string $value = null,
        public array $attributePaths = [],
    ) {}
}
