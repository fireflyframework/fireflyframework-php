<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Fixtures\AbstractClassLevelBase;

use Firefly\Observability\Method\Timed;

/**
 * The class-level base one step further out of reach: ABSTRACT, so the scan never walks it in its own right
 * (`classes()` keeps to instantiable classes, because only those can be beans) and PHP hands its class
 * attributes to nobody. Written here, the meter is inert in every configuration there is — which is why the
 * refusal comes from the first concrete descendant scanned, and names this file rather than that one.
 */
#[Timed('gateway.ops')]
abstract class AbstractGateway
{
    public function charge(int $cents): int
    {
        return $cents;
    }
}
