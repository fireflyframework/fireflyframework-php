<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\StaticFallback;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class StaticFallback
{
    #[FeatureFlag('x', fallback: 'recover')]
    public function run(): void {}

    public static function recover(): void {}
}
