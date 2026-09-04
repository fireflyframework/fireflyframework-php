<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

/**
 * The THIRD level of the nested-body fixture graph (MoneyTransferRequest -> AddressPayload -> GeoPoint).
 * Carries no constraints on purpose: hydration depth must not depend on a level having validation rules.
 */
final class GeoPoint
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lon,
    ) {}
}
