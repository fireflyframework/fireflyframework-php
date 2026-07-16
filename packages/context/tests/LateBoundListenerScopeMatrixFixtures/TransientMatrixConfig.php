<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Scope;

/**
 * Both factories declare `Scope::Transient` — never eagerly resolved by `EagerSingletonsPass`.
 * Named `TransientMatrixConfig` (not `TransientConfig`) to avoid colliding with
 * DedupeKeyFixtures\VaryConfig's own Transient fixture set, a separate PSR-4 root for a separate
 * finding (M4 review #8, Minor).
 */
#[Configuration]
final class TransientMatrixConfig
{
    #[Bean(scope: Scope::Transient)]
    public function makeConcrete(MatrixRecorder $recorder): TransientConcreteListener
    {
        return new TransientConcreteListener($recorder);
    }

    #[Bean(scope: Scope::Transient)]
    public function makeInterface(MatrixRecorder $recorder): TransientMatrixPort
    {
        return new TransientInterfaceListener($recorder);
    }
}
