<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

/**
 * An eda broker-bus listener (#[EventListener] is TARGET_METHOD): it subscribes to the "WidgetRegistered" event-type
 * pattern and records the delivered envelope's eventType into the shared ListenerSpy. Copied verbatim from the eda
 * package's RecordingListener fixture (the invoker always passes an EventEnvelope, never the domain object).
 */
#[Component]
final class WidgetEventListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('WidgetRegistered')]
    public function onRegistered(EventEnvelope $envelope): void
    {
        $this->spy->record($envelope->eventType);
    }
}
