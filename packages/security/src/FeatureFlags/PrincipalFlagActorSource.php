<?php

declare(strict_types=1);

namespace Firefly\Security\FeatureFlags;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Management\FlagActorSource;
use Firefly\Security\Core\SecurityContextHolder;

/**
 * The actor of a flag write is the authenticated principal's name (spec §4.8). Anonymous callers name nobody,
 * and the surface's own fallback — `actuator`, `admin`, `cli:<os-user>` — is recorded instead.
 */
#[Component]
#[ConditionalOnClass(FlagActorSource::class)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
final class PrincipalFlagActorSource implements FlagActorSource
{
    public function actor(): ?string
    {
        $authentication = SecurityContextHolder::getAuthentication();

        return $authentication !== null && $authentication->isAuthenticated() && $authentication->getName() !== ''
            ? $authentication->getName()
            : null;
    }
}
