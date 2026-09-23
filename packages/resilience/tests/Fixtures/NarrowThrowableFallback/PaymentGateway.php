<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\NarrowThrowableFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;
use Throwable;

/**
 * Spring `@Recover`'s mainstream signature, written against an advice that appends the cause LAST: the
 * recovery takes the Throwable and nothing else, which is the first thing a Spring-shaped framework's users
 * write. Its required-parameter count fits the guarded call's width exactly, so the arity proof alone waves
 * it through — and the interceptor then calls `queued('acct')`, putting the account number in a parameter
 * declared Throwable and fatalling with a TypeError from inside the catch that was absorbing the outage.
 * Reflection can prove that without running anything, because nothing outside the Exception/Error hierarchy
 * can implement Throwable, so a `string` argument is never one.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    #[Fallback(method: 'queued')]
    public function charge(string $account): string
    {
        return $account;
    }

    public function queued(Throwable $cause): string
    {
        return 'queued';
    }
}
