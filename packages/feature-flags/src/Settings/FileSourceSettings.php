<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Settings;

/** `firefly.feature-flags.sources.file.*`: a watched flagd document (.json, .yaml, .yml). */
final readonly class FileSourceSettings
{
    public function __construct(
        public bool $enabled,
        public string $path,
        public float $refreshInterval,
    ) {}
}
