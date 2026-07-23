<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\AttributeFixtures;

use Firefly\Cqrs\Command\Command;

final readonly class ProbeCommand implements Command
{
    public function __construct(public string $name) {}
}
