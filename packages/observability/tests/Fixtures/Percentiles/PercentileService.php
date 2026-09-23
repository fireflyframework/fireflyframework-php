<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\Percentiles;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * Micrometer's `percentiles:` written out of habit. The registry publishes fixed histogram buckets, not
 * client-side quantile summaries, so the scan refuses the parameter and names the key that does publish them.
 */
#[Service]
class PercentileService
{
    #[Timed('p', percentiles: [0.95])]
    public function quantiled(): void {}
}
