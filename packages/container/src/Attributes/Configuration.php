<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

/**
 * A source of #[Bean] factory methods. It is also a Component so it is itself managed.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Configuration extends Component {}
