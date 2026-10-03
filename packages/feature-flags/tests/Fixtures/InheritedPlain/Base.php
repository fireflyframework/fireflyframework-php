<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\InheritedPlain;

use Firefly\FeatureFlags\Gating\FeatureFlag;

class Base
{
    #[FeatureFlag('x')]
    public function run(): string
    {
        return 'run';
    }
}
