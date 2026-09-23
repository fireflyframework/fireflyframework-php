<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\ClassLevelFinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * The same fatal reached the other way: nobody wrote an attribute on the `final` method, the CLASS-LEVEL
 * #[Timed] fanned onto it. Skipping it silently would leave one flat line in an otherwise timed class, so the
 * refusal fires here too and names the method rather than the class.
 */
#[Service]
#[Timed('orders.svc')]
class InheritedFinalMethodService
{
    public function open(): void {}

    final public function sealed(): void {}
}
