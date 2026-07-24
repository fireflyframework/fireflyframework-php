<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures;

use Firefly\Security\Access\Attributes\PreAuthorize;
use Firefly\Security\Access\Attributes\RolesAllowed;
use Firefly\Security\Access\Attributes\Secured;

final class SecuredController
{
    #[PreAuthorize("hasPermission(#id, 'READ')")]
    public function show(int $id): string
    {
        return "shown {$id}";
    }

    #[RolesAllowed('ADMIN', 'STAFF')]
    public function destroy(int $id): void {}

    #[Secured('orders:write')]
    public function store(string $body): void {}

    public function unguarded(): void {}
}
