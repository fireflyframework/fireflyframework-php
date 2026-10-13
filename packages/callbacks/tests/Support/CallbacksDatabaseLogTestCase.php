<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Tests\Support;

use Firefly\Callbacks\CallbacksServiceProvider;
use Firefly\Callbacks\CallbacksWiringProvider;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Testing\FireflyDatabaseTestCase;

/**
 * Callbacks enabled with the database execution log (and the memory registry), over Testbench + sqlite :memory:.
 * firefly/resilience is registered because the deliverer's per-endpoint circuit breakers ride on its ResilienceStore.
 */
abstract class CallbacksDatabaseLogTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [EdaServiceProvider::class, ResilienceServiceProvider::class, CallbacksServiceProvider::class, CallbacksWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.resilience.method.enabled' => false,
            'firefly.callbacks.enabled' => true,
            'firefly.callbacks.log.store' => 'database',
        ];
    }
}
