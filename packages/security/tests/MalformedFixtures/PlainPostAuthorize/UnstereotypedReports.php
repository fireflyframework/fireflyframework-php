<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\PlainPostAuthorize;

use Firefly\Security\Access\Attributes\PostAuthorize;

/**
 * A #[PostAuthorize] on a class with no #[Component]-family stereotype: the component scan never registers it,
 * so no proxy wraps it, and neither the dispatcher nor the bus looks its rule up. Unlike a pre expression it
 * has no imperative equivalent, so the scan must refuse it rather than compile a rule nothing enforces.
 */
class UnstereotypedReports
{
    #[PostAuthorize("hasPermission(#returnObject, 'READ')")]
    public function find(int $id): object
    {
        return (object) ['id' => $id];
    }
}
