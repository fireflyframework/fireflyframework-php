<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\FinalMethod;

use Firefly\Container\Attributes\Service;
use Firefly\Observability\Method\Timed;

/**
 * A stereotyped, non-final bean whose TIMED method is `final`. The proxy the advice needs emits an override
 * for every planned method, so this one would compile into the plan and then fatal with "Cannot override
 * final method" at the `require` of the generated class, naming nothing that points back at the attribute.
 * The scan refuses it instead.
 */
#[Service]
class FinalMethodService
{
    #[Timed('orders.place')]
    final public function place(): void {}
}
