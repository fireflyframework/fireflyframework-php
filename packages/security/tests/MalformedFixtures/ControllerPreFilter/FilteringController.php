<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\ControllerPreFilter;

use Firefly\Security\Access\Attributes\PreFilter;
use Firefly\Web\Attributes\DeleteMapping;
use Firefly\Web\Attributes\RestController;

/**
 * A #[PreFilter] on a controller action: the dispatcher's guard evaluates the rule against a copy of the
 * resolved arguments and cannot hand the narrowed one back, so the action would receive the unfiltered list
 * with nothing thrown. The scan must refuse it, loudly, naming the site.
 */
#[RestController]
final class FilteringController
{
    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    #[PreFilter("hasPermission(#filterObject, 'WRITE')")]
    #[DeleteMapping('/malformed/reports')]
    public function purge(array $ids): array
    {
        return $ids;
    }
}
