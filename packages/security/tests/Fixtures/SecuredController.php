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

    #[PreAuthorize("hasAnyRole('MANAGER', 'TENANT_ADMIN')", code: 'RUN_ROLE_REQUIRED', message: 'Only a manager may start a run.')]
    public function start(): void {}

    public function unguarded(): void {}
}
