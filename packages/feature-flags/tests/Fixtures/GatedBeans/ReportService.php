<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\GatedBeans;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
#[FeatureFlag('reports')]
class ReportService
{
    public function daily(): string
    {
        return 'daily';
    }

    public function weekly(): string
    {
        return 'weekly';
    }
}
