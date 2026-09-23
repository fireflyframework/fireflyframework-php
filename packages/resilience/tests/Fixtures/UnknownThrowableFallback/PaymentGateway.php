<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\UnknownThrowableFallback;

use Firefly\Container\Attributes\Service;
use Firefly\Resilience\Method\Fallback;
use Firefly\Resilience\Method\Retry;

/**
 * A `on:` entry with a typo in it — the exception is `GatewayDownException`, the attribute says `GatwayDown`.
 * `$cause instanceof 'A\Name\Nothing\Declares'` is FALSE without autoloading and without erroring, so the
 * narrowed list matches nothing, the fallback never fires and the outage propagates exactly as it would with
 * no #[Fallback] at all — the silent no-op this scan exists to refuse, one field to the right of a misspelt
 * method name.
 */
#[Service]
class PaymentGateway
{
    #[Retry('payments')]
    // @phpstan-ignore argument.type (the unloadable class-string IS the case under test)
    #[Fallback(method: 'queued', on: ['App\Exceptions\GatwayDown'])]
    public function charge(string $account): string
    {
        return $account;
    }

    public function queued(string $account): string
    {
        return 'queued';
    }
}
