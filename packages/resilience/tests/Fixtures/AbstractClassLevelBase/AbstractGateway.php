<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\AbstractClassLevelBase;

use Firefly\Resilience\Method\CircuitBreaker;

/**
 * The class-level base one step further out of reach: ABSTRACT, so the scan never walks it in its own right
 * (`classes()` keeps to instantiable classes, because only those can be beans) and PHP hands its class
 * attributes to nobody. Written here, the breaker is inert in every configuration there is — which is why
 * the refusal comes from the first concrete descendant scanned, and names this file rather than that one.
 */
#[CircuitBreaker('payments')]
abstract class AbstractGateway
{
    public function charge(string $account): string
    {
        return $account;
    }
}
