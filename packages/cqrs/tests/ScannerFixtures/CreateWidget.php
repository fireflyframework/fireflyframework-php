<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Command\Command;

final readonly class CreateWidget implements Command
{
    public function __construct(public string $name) {}
}
