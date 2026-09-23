<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\SiblingSubclass;

use Firefly\Container\Attributes\Service;

/** The bean: it inherits `charge()` unchanged, so the base's attribute IS visible through it. */
#[Service]
class StripeGateway extends BaseGateway {}
