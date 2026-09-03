<?php

declare(strict_types=1);

namespace Firefly\Config\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;
use Firefly\Config\Profile\Profile;

/**
 * A #[ConfigProperties] DTO that is ALSO profile-gated. Exercises the whole config-side profile
 * chain in one fixture: ConfigPropertiesScanner records ['prod', 'staging'] on the descriptor,
 * ConfigManifestCompiler round-trips it through the compiled artifact, and ConfigRegistrar skips
 * the singleton binding entirely unless one of those profiles is active.
 */
#[ConfigProperties('audit')]
#[Profile('prod', 'staging')]
final readonly class AuditProperties
{
    public function __construct(
        public bool $enabled = false,
        public string $sink = 'stderr',
    ) {}
}
