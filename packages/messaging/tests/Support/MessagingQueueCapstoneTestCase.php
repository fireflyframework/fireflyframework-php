<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

/**
 * The queue variant: firefly.messaging.provider=queue over the `sync` driver, so publish() enqueues a
 * DispatchMessageJob that runs inline and delivers through the worker path — identical observable behaviour to the
 * in-memory capstone (the async→sync degradation contract).
 */
class MessagingQueueCapstoneTestCase extends MessagingCapstoneTestCase
{
    protected function messagingProvider(): string
    {
        return 'queue';
    }
}
