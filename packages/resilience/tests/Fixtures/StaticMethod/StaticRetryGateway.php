<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\StaticMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Retry;

/** A guard written by hand on a `public static`, which no proxy has an instance to wrap. */
#[Service]
class StaticRetryGateway
{
    #[Retry('payments')]
    public static function chargeAll(): void {}
}
