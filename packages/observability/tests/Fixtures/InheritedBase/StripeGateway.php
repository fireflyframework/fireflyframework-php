<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\InheritedBase;

use Firefly\Container\Attributes\Service;

/** The leaf that IS the bean, and through whose proxy the base's meter actually runs. */
#[Service]
class StripeGateway extends BaseGateway {}
