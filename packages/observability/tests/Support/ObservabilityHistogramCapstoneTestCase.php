<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

/** The capstone with buckets turned on for ONE meter, so the scrape shows a histogram beside untouched summaries. */
abstract class ObservabilityHistogramCapstoneTestCase extends ObservabilityCapstoneTestCase
{
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.observability.metrics.distribution.per-meter' => ['http_server_requests_seconds' => [0.05, 0.5, 5]],
        ];
    }
}
