<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

/**
 * ARM B: the `#[Bean]` method's declared return type is `ListenerPort`, an INTERFACE — "the canonical
 * hexagonal shape" (docs/modules/context.md) this whole framework is built around. The ONLY variable
 * versus ConfigA above.
 */
#[Configuration]
final class ConfigB
{
    #[Bean]
    public function makeB(ListenerFireRecorder $recorder): ListenerPort
    {
        return new CacheB($recorder);
    }
}
