<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\ClassLevelFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;
use RuntimeException;

/**
 * The other half of the rule: the shield is over the IMPLICIT fan-out ALONE. `chargeUnavailable()` carries a
 * #[Retry] of its own, so it is a site somebody wrote on purpose, naming an instance of their own choosing —
 * and it keeps its row, is overridden by the proxy and re-enters the resilience link, which is what writing
 * an attribute on a method means everywhere else in this scanner.
 *
 * What it does NOT pick up is the class's breaker: the shield still drops that, so a hand-annotated recovery
 * carries exactly what its author wrote on it and nothing the class fanned out. Asserting both halves on one
 * row is what keeps the implementation from degenerating into "a recovery is never advised" (which would
 * silently discard a written attribute) or "a recovery is advised whenever it carries anything" (which would
 * hand the class's breaker back through the side door).
 */
#[Service]
#[CircuitBreaker('payments')]
class AuditedPaymentGateway
{
    #[Fallback(method: 'chargeUnavailable')]
    public function charge(string $account): string
    {
        throw new RuntimeException('gateway down');
    }

    #[Retry('payments')]
    public function chargeUnavailable(string $account): string
    {
        return 'queued:'.$account;
    }
}
