<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * A #[ConfigProperties] DTO bound from the `foundation.*` config prefix. The constructor default DIFFERS from the
 * value the capstone seeds (so the assertion distinguishes "populated FROM config" from "bare autowired default" —
 * the same discriminator the T3 App\DemoConfigProperties uses). Bound on the CACHED path only, by
 * FireflyCacheServiceProvider via ConfigRegistrar from the emitted config-properties.php.
 */
#[ConfigProperties('foundation')]
final readonly class FoundationProperties
{
    public function __construct(public string $greeting = 'default-greeting') {}
}
