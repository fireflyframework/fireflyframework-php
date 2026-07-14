<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

#[Configuration]
final class EdgeConfig
{
    #[Bean]
    public function typedClass(): Gadget
    {
        return new Gadget;
    }

    #[Bean]
    public function builtinReturn(): string
    {
        return 'x';
    }

    #[Bean]
    public function nullableClass(?bool $make = null): ?Widget
    {
        return $make === true ? new Widget : null;
    }
}
