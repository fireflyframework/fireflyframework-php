<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\MixedSubclasses;

use Firefly\Container\Attributes\Service;

/** The child that inherits `charge()` and so compiles the base's meter as its own row. */
#[Service]
class InheritingGateway extends BaseGateway {}
