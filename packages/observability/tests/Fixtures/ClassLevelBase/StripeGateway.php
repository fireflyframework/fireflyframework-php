<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\ClassLevelBase;

use Firefly\Container\Attributes\Service;

/** The bean — and it carries no metric attribute of its own, because PHP did not hand it the base's. */
#[Service]
class StripeGateway extends BaseGateway {}
