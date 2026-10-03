<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

/** The same beans with firefly.feature-flags.enabled OFF: every gate must fail closed. */
abstract class GatedBeansDisabledTestCase extends GatedBeansTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.feature-flags.enabled' => false];
    }
}
