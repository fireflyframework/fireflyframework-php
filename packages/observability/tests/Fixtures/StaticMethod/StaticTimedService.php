<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\StaticMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/** The other half: a meter written by hand on a `public static`, which no proxy has an instance to wrap. */
#[Service]
class StaticTimedService
{
    #[Timed('email.bulk')]
    public static function bulk(): void {}
}
