<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;

/**
 * ARM C: the `#[Bean]` method's declared return type is the concrete SUPERCLASS `ParentCache`, but
 * the factory returns a `ChildCache` instance — the "widening concrete" shape M4 review #7 requires a
 * control for, alongside ConfigA (identical) and ConfigB (interface).
 */
#[Configuration]
final class ConfigC
{
    #[Bean]
    public function makeC(ListenerFireRecorder $recorder): ParentCache
    {
        return new ChildCache($recorder);
    }
}
