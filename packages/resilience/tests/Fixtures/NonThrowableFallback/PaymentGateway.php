<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\NonThrowableFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;
use stdClass;

/**
 * The other half of the `on:` rule: the entry LOADS, and nothing a `catch` can ever hold is an instance of
 * it. The list is well-formed PHP, compiles into the row verbatim and then matches nothing at runtime, which
 * is the same never-fires as the typo beside it and needs its own sentence to be actionable.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    // @phpstan-ignore argument.type (the non-Throwable class-string IS the case under test)
    #[Fallback(method: 'queued', on: [stdClass::class])]
    public function charge(string $account): string
    {
        return $account;
    }

    public function queued(string $account): string
    {
        return 'queued';
    }
}
