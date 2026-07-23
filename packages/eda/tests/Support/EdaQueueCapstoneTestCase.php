<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

/**
 * The queue variant: firefly.eda.provider=queue over the `sync` queue driver, so publish() enqueues a
 * DispatchEventJob that runs inline on the same process and delivers through the worker path. Identical observable
 * behaviour to the in-memory capstone — which is exactly the async→sync degradation contract.
 */
class EdaQueueCapstoneTestCase extends EdaCapstoneTestCase
{
    protected function edaProvider(): string
    {
        return 'queue';
    }
}
