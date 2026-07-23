<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Fixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Message;
use RuntimeException;

#[Component]
final class FailingConsumer
{
    #[MessageListener(topic: 'failing', retries: 2, retryDelay: 0.0, deadLetterTopic: 'failing.DLT')]
    public function consume(Message $message): void
    {
        throw new RuntimeException('consumer boom');
    }
}
