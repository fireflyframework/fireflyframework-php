<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\SelfFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;

/**
 * A #[Fallback] naming the guarded method ITSELF — the second-most-obvious mistake after a typo, and one
 * every other check here waves through: the method exists, and its signature receives its own arguments by
 * construction. Run, the recovery calls the method back through the proxy, which re-enters the interceptor,
 * fails again, recovers again — unbounded recursion ending in a stack overflow, and no faster with a breaker,
 * because an open breaker's refusal is caught by the same fallback that calls the method again.
 */
#[Service]
class PaymentGateway
{
    #[CircuitBreaker('payments')]
    #[Fallback(method: 'charge')]
    public function charge(string $account): string
    {
        return $account;
    }
}
