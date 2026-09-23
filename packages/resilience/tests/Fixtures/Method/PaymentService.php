<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\Method;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Bulkhead;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\RateLimiter;
use Firefly\Resilience\Method\Retry;
use Firefly\Resilience\Method\TimeLimiter;
use RuntimeException;
use Throwable;

/**
 * The well-formed shape: all six attributes on one method, one pattern alone on another, and a method that
 * carries nothing at all — so the scan is proved to compile exactly the rows somebody wrote and no more.
 */
#[Service]
class PaymentService
{
    #[Bulkhead('payments')]
    #[TimeLimiter('payments')]
    #[RateLimiter('payments')]
    #[CircuitBreaker('payments')]
    #[Retry('payments')]
    #[Fallback(method: 'chargeUnavailable')]
    public function charge(string $account, int $cents): string
    {
        throw new RuntimeException('gateway down');
    }

    public function chargeUnavailable(string $account, int $cents, ?Throwable $cause = null): string
    {
        return 'queued';
    }

    #[Retry('payments')]
    public function refund(string $id): string
    {
        return $id;
    }

    public function untouched(): void {}
}
