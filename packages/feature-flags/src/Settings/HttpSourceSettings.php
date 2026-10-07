<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Settings;

/** `firefly.feature-flags.sources.http.*`: another service's sync endpoint, polled with If-None-Match. */
final readonly class HttpSourceSettings
{
    public function __construct(
        public bool $enabled,
        public string $url,
        public string $token,
        public float $refreshInterval,
        public float $timeout,
    ) {}
}
