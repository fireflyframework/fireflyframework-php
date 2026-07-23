<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\ScannerFixtures;

use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Message;

final class SampleConsumer
{
    #[MessageListener(topic: 'orders', group: 'workers', retries: 3, retryDelay: 0.5, deadLetterTopic: 'orders.DLT')]
    public function consume(Message $message): void
    {
        // no-op fixture
    }
}
