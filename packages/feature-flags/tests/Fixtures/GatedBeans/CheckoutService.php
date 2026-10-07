<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\GatedBeans;

use Firefly\Container\Attributes\Service;
use Firefly\FeatureFlags\Gating\FeatureFlag;

#[Service]
class CheckoutService
{
    #[FeatureFlag('new-checkout')]
    public function checkout(string $cart): string
    {
        return "new:{$cart}";
    }

    #[FeatureFlag('new-pricing', fallback: 'legacyPrice')]
    public function price(int $cents): string
    {
        return "new:{$cents}";
    }

    public function legacyPrice(int $cents): string
    {
        return "legacy:{$cents}";
    }

    #[FeatureFlag('not-defined-anywhere', default: true)]
    public function optOut(): string
    {
        return 'ran';
    }

    public function untouched(): string
    {
        return 'free';
    }
}
