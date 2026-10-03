<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\GatedBeans;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

class FallbackBase
{
    public function run(string $value): string
    {
        return 'new:'.$value;
    }

    public function recover(string $value): string
    {
        return 'base:'.$value;
    }
}

#[Service]
#[FeatureFlag('reports', fallback: 'recover')]
class VirtualFallbackService extends FallbackBase
{
    public function recover(string $value): string
    {
        return 'child:'.$value;
    }
}
