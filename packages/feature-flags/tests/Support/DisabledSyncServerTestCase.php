<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

abstract class DisabledSyncServerTestCase extends SyncServerTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.feature-flags.server.enabled' => false];
    }
}
