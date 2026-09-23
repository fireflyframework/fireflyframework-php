<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\ClassLevelFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;
use RuntimeException;
use Throwable;

/**
 * THE SHAPE THAT BREAKS IF THE FAN-OUT REACHES A RECOVERY: one class-level pattern, one method-level
 * #[Fallback]. It is the commonest way an application writes resilience — "this whole gateway rides one
 * breaker, and this one call has a degraded answer" — and it is exactly the combination in which the
 * class-level rule and the recovery meet.
 *
 * Without ResilienceMethodScanner::recoveryMethods(), `chargeUnavailable()` compiles a row of its own
 * carrying the class's breaker, the generated proxy overrides it like any other planned method, and the
 * recovery is guarded by the SAME breaker instance that has just opened on the failure it exists to absorb.
 * The call then fails twice: RuntimeException from the method, CircuitBreakerOpenException from the recovery
 * — the second one raised from inside the catch that was handling the first, which is the one moment the
 * fallback must not fail. The capstone over this fixture asserts the breaker really is OPEN at the moment
 * the recovery returns, so "the recovery ran" cannot pass for the wrong reason.
 *
 * `payments` is the instance name every capstone block here already configures
 * (`circuit-breaker.payments.failure-threshold => 1`), so ONE failed call is enough to trip it.
 */
#[Service]
#[CircuitBreaker('payments')]
class PaymentGateway
{
    public int $calls = 0;

    #[Fallback(method: 'chargeUnavailable')]
    public function charge(string $account): string
    {
        $this->calls++;

        throw new RuntimeException('gateway down');
    }

    public function chargeUnavailable(string $account, ?Throwable $cause = null): string
    {
        return 'queued:'.$account;
    }
}
