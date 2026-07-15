<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Lazy;

/**
 * A #[Configuration] with one #[Lazy] #[Bean] factory method and one eager one:
 * ComponentScanner must capture #[Lazy] onto BeanDescriptor::$lazy so
 * EagerSingletonsPass can skip it without reflecting.
 */
#[Configuration]
final class LazyBeanConfig
{
    #[Bean]
    #[Lazy]
    public function lazyGadget(): Gadget
    {
        return new Gadget;
    }

    #[Bean]
    public function eagerGadget(): Gadget
    {
        return new Gadget;
    }
}
