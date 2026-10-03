<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\Refused\ArityFallback;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class ArityFallback
{
    #[FeatureFlag('x', fallback: 'recover')]
    public function run(): void {}

    public function recover(string $value): void {}
}
