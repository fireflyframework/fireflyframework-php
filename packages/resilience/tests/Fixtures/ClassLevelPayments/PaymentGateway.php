<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\ClassLevelPayments;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\CircuitBreaker;
use Firefly\Resilience\Method\Retry;

/**
 * Resilience4j's class-level shape, which five of the six attributes promise in their docblocks: a
 * class-level attribute applies to EVERY public method, and a method-level one of the SAME KIND replaces it
 * for that method alone. The replacement is per kind rather than wholesale — `refund()` names its own retry
 * instance and keeps the class's breaker — because the five patterns are resolved independently, which is
 * the one line of scanner logic (`?? $classRetry`) most able to regress in silence.
 *
 * The two methods the fan-out must NOT reach are here for the same reason: a static call has no instance for
 * a proxy to wrap, and the `__firefly*` members the generated proxy declares make the magic methods its own.
 * Neither carries an attribute of its own, so the fan-out passes over both in SILENCE — an attribute written
 * BY HAND on either shape is a refusal, and lives in its own fixture directory.
 */
#[Service]
#[Retry('payments')]
#[CircuitBreaker('payments')]
class PaymentGateway
{
    public function charge(string $account): string
    {
        return $account;
    }

    #[Retry('refunds')]
    public function refund(string $id): string
    {
        return $id;
    }

    public static function reconcileAll(): void {}

    public function __invoke(): void {}
}
