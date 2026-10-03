<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Fixtures\GatedRoutes;

use Firefly\FeatureFlags\Gating\FeatureFlag;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RequestBody;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
#[RequestMapping('/beta')]
class BetaController
{
    public static int $calls = 0;

    /** @return array<string, string> */
    #[FeatureFlag('beta-api')]
    #[PostMapping]
    public function create(#[RequestBody] BetaPayload $payload): array
    {
        self::$calls++;

        return ['sku' => $payload->sku];
    }

    /** @return array<string, string> */
    #[FeatureFlag('beta-api', fallback: 'createFallback')]
    #[PostMapping('/fallback')]
    public function createWithFallback(#[RequestBody] BetaPayload $payload): array
    {
        self::$calls++;

        return ['sku' => $payload->sku];
    }

    /** @return array<string, string> */
    public function createFallback(BetaPayload $payload): array
    {
        return ['fallback' => $payload->sku];
    }

    /** @return array<string, bool> */
    #[FeatureFlag('missing-nested-gate', default: true)]
    #[GetMapping('/open-calls-closed')]
    public function openCallsClosed(): array
    {
        self::$calls++;

        return $this->closed();
    }

    /** @return array<string, bool> */
    #[FeatureFlag('missing-nested-gate')]
    #[GetMapping('/closed')]
    public function closed(): array
    {
        self::$calls++;

        return ['closed' => true];
    }

    /** @return array<string, bool> */
    #[FeatureFlag('missing-nested-gate', default: true)]
    #[GetMapping('/open-calls-open')]
    public function openCallsOpen(): array
    {
        self::$calls++;

        return $this->nestedOpen();
    }

    /** @return array<string, bool> */
    #[FeatureFlag('missing-nested-gate', default: true)]
    #[GetMapping('/nested-open')]
    public function nestedOpen(): array
    {
        self::$calls++;

        return ['open' => true];
    }

    /** @return array<string, string> */
    #[FeatureFlag('checkout-flow', variant: 'v2')]
    #[GetMapping('/v2')]
    public function v2(): array
    {
        self::$calls++;

        return ['flow' => 'v2'];
    }

    /** @return array<string, bool> */
    #[GetMapping('/open')]
    public function open(): array
    {
        return ['open' => true];
    }
}
