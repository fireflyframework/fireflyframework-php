<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * A #[ConfigProperties] DTO that BOUND, so the Config properties page has bound values to list.
 *
 * Three promoted public properties, because the listing draws ONE ROW PER PROPERTY rather than one row per
 * DTO — a fixture with a single property could not tell the two shapes apart. The types are deliberately
 * mixed (string, int, bool) so `AdminAction::scalar()` has to do its work: `true` reaches the page as the
 * word `true`, which is what a reader comparing a dashboard against a config file needs to see.
 *
 * Nothing here is named like a secret. The endpoint masks resolved values through SensitiveValueMasker, and
 * a fixture property called `$apiToken` would arrive as `***` and quietly assert the masker instead of the
 * listing.
 */
#[ConfigProperties(prefix: 'billing')]
final readonly class BillingProperties
{
    public function __construct(
        public string $currency = 'EUR',
        public int $dailyTransferLimitMinor = 250000,
        public bool $dunningEnabled = true,
    ) {}
}
