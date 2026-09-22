<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

use Firefly\Cqrs\Attributes\CommandHandler;
use Firefly\Security\Access\Attributes\PreAuthorize;

/** A final handler with a PRE rule only: the bus enforces it, so the proxy plan must leave it alone. */
#[CommandHandler]
final class ArchiveHandler
{
    #[PreAuthorize("hasRole('ADMIN')")]
    public function handle(ArchiveCommand $command): string
    {
        return 'archived:'.$command->id;
    }
}
