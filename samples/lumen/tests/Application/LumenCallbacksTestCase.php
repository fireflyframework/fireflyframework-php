<?php

declare(strict_types=1);

namespace Lumen\Tests\Application;

use Firefly\Callbacks\CallbacksServiceProvider;
use Firefly\Callbacks\CallbacksWiringProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Illuminate\Support\ServiceProvider;
use Lumen\Tests\LumenTestCase;

/**
 * The lumen boot harness with firefly/callbacks on: the dispatcher subscribes to every `Funds*` event on the sample's
 * bus, and the one configured subscription, `partner-ledger`, receives FundsDeposited and FundsWithdrawn as signed
 * POSTs (sha256 HMAC over "<timestamp>.<body>"). firefly/resilience supplies the per-endpoint circuit breaker, on the
 * array cache store; the first retry is immediate so a failing partner does not slow the suite.
 */
abstract class LumenCallbacksTestCase extends LumenTestCase
{
    public const string SECRET = 'lumen-partner-secret';

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), ResilienceServiceProvider::class, CallbacksServiceProvider::class, CallbacksWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'cache.default' => 'array',
            'firefly.resilience.method.enabled' => false,
            'firefly.callbacks.enabled' => true,
            'firefly.callbacks.event-types' => ['Funds*'],
            'firefly.callbacks.retry.initial-delay' => 0,
            'firefly.callbacks.subscriptions' => [[
                'id' => 'partner-ledger',
                'name' => 'partner ledger',
                'url' => 'https://partner.example.com/hooks/wallet',
                'event-types' => ['FundsDeposited', 'FundsWithdrawn'],
                'secret' => self::SECRET,
            ]],
        ];
    }
}
