<?php

declare(strict_types=1);

namespace Firefly\Actuator\Info;

/** Contributes one fragment to /info. #[Component] contributors are discovered by InfoContributorRegistrar. */
interface InfoContributor
{
    /** @return array<string, mixed> */
    public function info(): array;
}
