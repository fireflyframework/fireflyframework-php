<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Tests\Support;

use Firefly\Eda\EventPublisher;
use RuntimeException;

/** The same application on a bus whose publish always throws. */
abstract class FailingPublisherWebhooksCapstoneTestCase extends WebhooksCapstoneTestCase
{
    protected function bus(): EventPublisher
    {
        return new class implements EventPublisher
        {
            public function subscribe(string $eventTypePattern, callable $handler): void {}

            public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
            {
                throw new RuntimeException('broker down');
            }

            public function start(): void {}

            public function stop(): void {}
        };
    }
}
