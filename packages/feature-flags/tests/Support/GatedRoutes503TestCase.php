<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

abstract class GatedRoutes503TestCase extends GatedRoutesTestCase
{
    protected function disabledStatus(): int
    {
        return 503;
    }
}
