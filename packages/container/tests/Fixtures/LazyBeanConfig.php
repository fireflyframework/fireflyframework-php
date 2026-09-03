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
 *
 * Both methods return Gadget, and EdgeConfig::typedClass() returns it too, so all
 * three carry an explicit #[Bean] name: competing beans of one type must stay
 * individually addressable (see ContainerRegistrar::registerBeans()).
 */
#[Configuration]
final class LazyBeanConfig
{
    #[Bean('lazyGadget')]
    #[Lazy]
    public function lazyGadget(): Gadget
    {
        return new Gadget;
    }

    #[Bean('eagerGadget')]
    public function eagerGadget(): Gadget
    {
        return new Gadget;
    }
}
