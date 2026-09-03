<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\DocFixture;

use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\Min;
use Firefly\Validation\Constraint\NotBlank;

/**
 * A request to hold stock for a shopper who has not paid yet.
 *
 * Reservations expire on their own. A caller is expected to confirm or release one rather than let it lapse,
 * because a lapsed reservation is indistinguishable from an abandoned basket in the stock reports.
 */
final class ReservationRequest
{
    /**
     * The three ways a member can be described, one per parameter, so the precedence between them is
     * observable rather than asserted: `$basket` has only this `@param`, `$sku` has its own docblock, and
     * `$minutes` has both — the closer one must win.
     *
     * @param  string  $basket  the shopper's basket, as returned by POST /baskets
     * @param  int  $minutes  ignored, because the promoted property below says it better
     */
    public function __construct(
        #[NotBlank]
        public readonly string $basket,
        /** The catalogue line to hold. Exactly one line may be reserved per request. */
        #[NotBlank]
        public readonly string $sku,
        /** How long to hold the stock for, in minutes from now. */
        #[Min(1)]
        #[Max(60)]
        public readonly int $minutes = 15,
    ) {}
}
