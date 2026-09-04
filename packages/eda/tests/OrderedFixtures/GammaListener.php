<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\OrderedFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

/** Ordering fixture C of three — the MIDDLE declared order, so it must run second. See AlphaListener's docblock. */
#[Component]
final class GammaListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('ordered.*', order: 20)]
    public function onOrdered(EventEnvelope $envelope): void
    {
        $this->spy->record('gamma');
    }
}
