<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

#[Configuration]
final class AppConfig
{
    #[Bean('utcClock')]
    public function clock(): Clock
    {
        return new Clock('UTC');
    }
}
