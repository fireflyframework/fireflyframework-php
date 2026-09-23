<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\MixedSubclasses;

use Firefly\Container\Attributes\Service;

/** The child that inherits `charge()` and so compiles the base's rule as its own row. */
#[Service]
class InheritingGateway extends BaseGateway {}
