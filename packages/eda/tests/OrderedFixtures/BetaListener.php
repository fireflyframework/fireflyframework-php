<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\OrderedFixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use Firefly\Testing\Fixture\ListenerSpy;

/** Ordering fixture B of three — the LOWEST declared order, so it must run FIRST. See AlphaListener's docblock. */
#[Component]
final class BetaListener
{
    public function __construct(private readonly ListenerSpy $spy) {}

    #[EventListener('ordered.*', order: 10)]
    public function onOrdered(EventEnvelope $envelope): void
    {
        $this->spy->record('beta');
    }
}
