<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Fixtures\Probe;

use Firefly\Container\Attributes\Service;

#[Service]
final class ProbeComponent
{
    public function ping(): string
    {
        return 'pong';
    }
}
