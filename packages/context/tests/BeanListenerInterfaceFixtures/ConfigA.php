<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

/** ARM A: the `#[Bean]` method's declared return type is the CONCRETE class CacheA. */
#[Configuration]
final class ConfigA
{
    #[Bean]
    public function makeA(ListenerFireRecorder $recorder): CacheA
    {
        return new CacheA($recorder);
    }
}
