<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler(RenameWidget::class)]
final class RenameWidgetHandler
{
    public function handle(object $command): string
    {
        return 'renamed';
    }
}
