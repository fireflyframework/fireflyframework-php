<?php

declare(strict_types=1);

namespace Firefly\Config\Attributes;

use Attribute;

/**
 * Binds a configuration subtree (config($prefix)) onto the annotated readonly DTO; the bound instance is
 * registered as a container singleton so it can be injected wherever the DTO type is requested.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ConfigProperties
{
    public function __construct(public string $prefix) {}
}
