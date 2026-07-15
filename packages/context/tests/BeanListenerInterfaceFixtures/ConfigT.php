<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Scope;

/**
 * M4 review #7, Minor 1: an interface-returning `#[Bean]` factory declared `Scope::Transient`, so
 * every resolution rebuilds `CacheT` and re-invokes the composite extender — see `CacheT`'s own
 * docblock for why this, and not `Scope::Singleton`, is what actually exercises the dedupe guard.
 */
#[Configuration]
final class ConfigT
{
    #[Bean(scope: Scope::Transient)]
    public function makeT(ListenerFireRecorder $recorder): TransientListenerPort
    {
        return new CacheT($recorder);
    }
}
