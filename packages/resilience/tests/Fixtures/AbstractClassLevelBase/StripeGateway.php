<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\AbstractClassLevelBase;

use Firefly\Container\Attributes\Service;

/** The bean — and, reflected, a class with no resilience attribute anywhere on it. */
#[Service]
class StripeGateway extends AbstractGateway {}
