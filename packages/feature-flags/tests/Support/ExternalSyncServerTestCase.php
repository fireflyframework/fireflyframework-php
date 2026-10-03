<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

abstract class ExternalSyncServerTestCase extends SyncServerTestCase
{
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.scan.paths' => ['Firefly\\FeatureFlags\\Tests\\Fixtures\\External\\' => dirname(__DIR__).'/Fixtures/External'],
        ];
    }
}
