<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

abstract class CustomPathSyncServerTestCase extends SyncServerTestCase
{
    protected function configOverrides(): array
    {
        return [...parent::configOverrides(), 'firefly.feature-flags.server.path' => '/internal/flags.json'];
    }
}
