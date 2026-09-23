<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\InheritedBase;

use Firefly\Observability\Method\Timed;

/**
 * The template-method shape: a CONCRETE base carrying the meter, with the stereotype on the leaf below. The
 * base is not a bean and never will be, but the metric records all the same — the child exposes `charge()`,
 * so the child compiles its own row for it and the child's proxy overrides the inherited body. Refusing this
 * would hard-fail `firefly:cache` on a legitimate shape while telling its author something untrue about their
 * own wiring.
 */
class BaseGateway
{
    #[Timed('gateway.charge')]
    public function charge(int $cents): int
    {
        return $cents;
    }
}
