<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

#[Configuration]
final class EdgeConfig
{
    // NAMED because three fixture #[Bean] methods across two #[Configuration]
    // classes all return Gadget. Competing beans of one type must each carry an
    // explicit name so they stay individually addressable — see
    // ContainerRegistrar::registerBeans().
    #[Bean('edgeGadget')]
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
