<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Lazy {}
