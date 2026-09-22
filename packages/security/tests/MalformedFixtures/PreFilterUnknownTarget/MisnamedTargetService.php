<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\PreFilterUnknownTarget;

use Firefly\Container\Attributes\Service;
use Firefly\Security\Access\Attributes\PreFilter;

/** A #[PreFilter] naming a filterTarget the method does not declare: the scan must refuse it, naming the site. */
#[Service]
class MisnamedTargetService
{
    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    #[PreFilter("hasPermission(#filterObject, 'WRITE')", filterTarget: 'identifiers')]
    public function purge(array $ids): array
    {
        return $ids;
    }
}
