<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Lazy;

/**
 * Both factories declare `#[Lazy]` (default `Scope::Singleton`) — `EagerSingletonsPass` skips both at
 * boot; the container never resolves either bean unless application code (or a test) asks for it.
 */
#[Configuration]
final class LazyConfig
{
    #[Bean]
    #[Lazy]
    public function makeConcrete(MatrixRecorder $recorder): LazyConcreteListener
    {
        return new LazyConcreteListener($recorder);
    }

    #[Bean]
    #[Lazy]
    public function makeInterface(MatrixRecorder $recorder): LazyPort
    {
        return new LazyInterfaceListener($recorder);
    }
}
