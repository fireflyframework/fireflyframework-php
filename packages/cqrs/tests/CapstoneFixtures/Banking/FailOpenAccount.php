<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\CapstoneFixtures\Banking;

use Firefly\Cqrs\Command\Command;

final readonly class FailOpenAccount implements Command
{
    public function __construct(public string $owner, public int $balance) {}
}
