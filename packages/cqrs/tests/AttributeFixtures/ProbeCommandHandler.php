<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\AttributeFixtures;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler(ProbeCommand::class)]
final class ProbeCommandHandler
{
    public function handle(ProbeCommand $command): string
    {
        return 'handled:'.$command->name;
    }
}
