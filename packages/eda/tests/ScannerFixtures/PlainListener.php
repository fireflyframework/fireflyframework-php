<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\ScannerFixtures;

use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;

/** The common shape: event-type patterns only, no declared broker destinations. Compiles to destinations: []. */
final class PlainListener
{
    #[EventListener('user.*')]
    public function on(EventEnvelope $envelope): void
    {
        // no-op fixture
    }
}
