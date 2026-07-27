<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Fixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

#[Component]
final class RecordingListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('order.*')]
    public function onOrder(EventEnvelope $envelope): void
    {
        $this->spy->record($envelope->eventType);
    }
}
