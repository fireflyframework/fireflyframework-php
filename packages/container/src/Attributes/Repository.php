<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Repository extends Component {}
