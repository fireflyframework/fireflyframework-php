<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Settings;

/** `firefly.feature-flags.server.*`: the flagd sync endpoint this application serves. */
final readonly class ServerSettings
{
    public function __construct(
        public bool $enabled,
        public string $path,
        public string $token,
        public bool $allowAnonymous,
    ) {}
}
