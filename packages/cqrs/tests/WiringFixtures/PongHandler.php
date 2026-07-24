<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\WiringFixtures;

use Firefly\Cqrs\Attributes\QueryHandler;

#[QueryHandler]
final class PongHandler
{
    public function handle(Pong $query): string
    {
        return 'pong';
    }
}
