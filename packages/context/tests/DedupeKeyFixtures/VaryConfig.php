<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Scope;

/**
 * A `Scope::Transient` `#[Bean]` factory: every `make(VaryPort::class)` call re-invokes this method
 * AND re-invokes `RegisterBeanPostProcessorsPass`'s composite extender for `VaryPort`. `$calls` is an
 * instance property on THIS `#[Configuration]` (a `Scope::Singleton` component by default, so one
 * instance persists across every factory call within a single container), used purely to vary the
 * returned concrete class across resolutions — unusual, but not forbidden, per the guard's own
 * disclosed edge case.
 */
#[Configuration]
final class VaryConfig
{
    private int $calls = 0;

    #[Bean(scope: Scope::Transient)]
    public function makeVary(DedupeRecorder $recorder): VaryPort
    {
        $this->calls++;

        return $this->calls === 1 ? new VaryImplA($recorder) : new VaryImplB($recorder);
    }
}
