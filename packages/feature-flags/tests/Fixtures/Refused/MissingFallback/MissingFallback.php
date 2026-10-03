<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\MissingFallback;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class MissingFallback
{
    #[FeatureFlag('x', fallback: 'nope')]
    public function run(): void {}
}
