<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\PlainHelper;

use Firefly\FeatureFlags\Gating\FeatureFlag;

class PlainHelper
{
    #[FeatureFlag('x')]
    public function run(): void {}
}
