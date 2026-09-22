<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Resolver;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Tag {}
