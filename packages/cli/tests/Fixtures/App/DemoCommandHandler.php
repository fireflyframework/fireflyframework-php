<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler]
final class DemoCommandHandler
{
    public function handle(DemoCommand $command): string
    {
        return 'handled:'.$command->name;
    }
}
