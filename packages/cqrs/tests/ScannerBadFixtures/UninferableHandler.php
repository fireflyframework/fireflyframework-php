<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerBadFixtures;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler]
final class UninferableHandler
{
    public function handle(object $command): void {}
}
