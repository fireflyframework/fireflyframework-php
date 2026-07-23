<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Fixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Messaging\Attributes\MessageListener;
use Firefly\Messaging\Message;

#[Component]
final class OrderConsumer
{
    public function __construct(private readonly Spy $spy) {}

    #[MessageListener(topic: 'orders')]
    public function consume(Message $message): void
    {
        $this->spy->record($message->topic);
    }
}
