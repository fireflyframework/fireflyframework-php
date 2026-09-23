<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\ReturnFixture;

/**
 * One batch of results and how many there are in all.
 *
 * Shaped exactly like firefly/data's Page — a template parameter that only a constructor `@param` mentions —
 * because that is the generic every paged endpoint returns, and it lives in a package this one may not import.
 *
 * @template T
 */
final readonly class Batch
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {}
}
