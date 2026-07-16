<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Scope;

/**
 * Both factories declare `Scope::Scoped` — never eagerly resolved by `EagerSingletonsPass`
 * regardless of laziness (only `Scope::Singleton` is ever eager).
 */
#[Configuration]
final class ScopedConfig
{
    #[Bean(scope: Scope::Scoped)]
    public function makeConcrete(MatrixRecorder $recorder): ScopedConcreteListener
    {
        return new ScopedConcreteListener($recorder);
    }

    #[Bean(scope: Scope::Scoped)]
    public function makeInterface(MatrixRecorder $recorder): ScopedPort
    {
        return new ScopedInterfaceListener($recorder);
    }
}
