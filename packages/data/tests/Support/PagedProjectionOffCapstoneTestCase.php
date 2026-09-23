<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

/**
 * The capstone boot with `firefly.data.projection.pageable` turned OFF — the escape hatch an application flips
 * for one release while its call sites still expect the list a projection with a Pageable used to hand back.
 * Nothing else differs from DataCapstoneTestCase, so what the sibling suite proves about the ON path and what
 * this one proves about the OFF path differ by exactly the one config key.
 */
abstract class PagedProjectionOffCapstoneTestCase extends DataCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.data.projection.pageable' => false];
    }
}
