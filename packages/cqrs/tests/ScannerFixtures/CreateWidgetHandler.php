<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler]
final class CreateWidgetHandler
{
    public function handle(CreateWidget $command): string
    {
        return 'created:'.$command->name;
    }
}
