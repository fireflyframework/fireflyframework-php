<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\WiringFixtures;

use Firefly\Cqrs\Attributes\CommandHandler;

#[CommandHandler]
final class PingHandler
{
    public function __construct(public string $tag = 'default') {}

    public function handle(Ping $command): string
    {
        return $this->tag;
    }
}
