<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;
use Firefly\Config\Profile\Profile;

/** #[Profile]-gated: ConfigRegistrar does not bind it outside `prod`, so /configprops must report it unbound. */
#[ConfigProperties(prefix: 'prodonly')]
#[Profile('prod')]
final readonly class ProdOnlyProperties
{
    public function __construct(public string $endpoint) {}
}
