<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

final class FailingCommandHandler
{
    public function handle(FailingCommand $command): never
    {
        throw Failures::make($command->failure);
    }
}
