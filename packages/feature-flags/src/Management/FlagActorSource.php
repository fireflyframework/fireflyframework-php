<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

/**
 * Who is making a flag write. firefly/security contributes the authenticated principal's name (a #[Component]
 * collected through Container::getAll(), Security -> FeatureFlags); without one the caller's fallback is
 * recorded — `actuator`, `admin` or `cli:<os-user>` (spec §4.8).
 */
interface FlagActorSource
{
    public function actor(): ?string;
}
