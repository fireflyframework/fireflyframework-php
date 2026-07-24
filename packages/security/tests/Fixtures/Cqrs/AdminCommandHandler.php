<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

final class AdminCommandHandler
{
    public function handle(AdminCommand $command): string
    {
        return 'ok';
    }
}
