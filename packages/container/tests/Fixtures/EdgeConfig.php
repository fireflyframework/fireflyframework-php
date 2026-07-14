<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

#[Configuration]
final class EdgeConfig
{
    #[Bean]
    public function typedClass(): Clock
    {
        return new Clock('UTC');
    }

    #[Bean]
    public function builtinReturn(): string
    {
        return 'x';
    }

    #[Bean]
    public function nullableClass(?string $zone = null): ?Clock
    {
        return $zone === null ? null : new Clock($zone);
    }
}
