<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

/**
 * Reserved for forward-compatibility: not yet consumed by ContainerRegistrar.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Lazy {}
