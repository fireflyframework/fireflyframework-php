<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\GatedRoutes;

final readonly class BetaPayload
{
    public function __construct(public string $sku) {}
}
