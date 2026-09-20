<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\PreFilterAmbiguousTarget;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PreFilter;

/**
 * A #[PreFilter] with no filterTarget on a method declaring TWO iterable parameters: inference would have to
 * guess, and a filter that guessed the wrong one would let the other through unfiltered. The scan must
 * refuse it, naming the site.
 */
#[Service]
class TwoListsService
{
    /**
     * @param  list<int>  $keep
     * @param  iterable<int>  $drop
     * @return list<int>
     */
    #[PreFilter("hasPermission(#filterObject, 'WRITE')")]
    public function merge(array $keep, iterable $drop): array
    {
        return $keep;
    }
}
