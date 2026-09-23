<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * A node and the nodes beneath it.
 *
 * @template T
 */
final readonly class Tree
{
    /**
     * @param  T  $value
     * @param  list<Tree<T>>  $children
     */
    public function __construct(
        public mixed $value,
        public array $children,
    ) {}
}
