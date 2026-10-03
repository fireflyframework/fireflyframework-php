<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Gating;

use Attribute;

/** Gate a class's public methods or one method; an off flag invokes the named fallback or refuses the call. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class FeatureFlag
{
    public function __construct(
        public string $key,
        public ?string $variant = null,
        public bool $default = false,
        public ?string $fallback = null,
    ) {}
}
