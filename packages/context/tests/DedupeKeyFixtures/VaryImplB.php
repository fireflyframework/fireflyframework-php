<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

use Firefly\Context\Event\AsEventListener;

final class VaryImplB implements VaryPort
{
    public function __construct(private readonly DedupeRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(VaryEvent $event): void
    {
        $this->recorder->record('ImplB:listener');
    }
}
