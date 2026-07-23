<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\ScannerFixtures;

use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;

final class SampleListener
{
    #[EventListener(['user.*', 'order.created'], order: 5)]
    public function on(EventEnvelope $envelope): void
    {
        // no-op fixture
    }
}
