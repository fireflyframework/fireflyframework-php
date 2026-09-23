<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\InheritedBase;

use Firefly\Container\Attributes\Service;

/** The leaf that IS the bean, and through whose proxy the base's retry actually runs. */
#[Service]
class StripeGateway extends BaseGateway {}
