<?php

declare(strict_types=1);

namespace Firefly\Actuator\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * Required, non-nullable, no default, and nothing under its prefix in config — so ReflectionConfigBinder throws
 * a ConfigurationException the first time anything resolves it. /configprops must report that on the row and
 * keep rendering every other DTO.
 */
#[ConfigProperties(prefix: 'unbindable')]
final readonly class UnbindableProperties
{
    public function __construct(public string $mandatory) {}
}
