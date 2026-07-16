<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

use Firefly\Context\Event\AsEventListener;

/**
 * Implements BOTH ReadPort and WritePort. RepoConfig's two `#[Bean]` factories each construct a
 * SEPARATE `Repo` instance (one bound as ReadPort, one as WritePort) — the container holds two
 * distinct singletons of this one concrete class, exactly the shape M4 review #8's Minor names.
 */
final class Repo implements ReadPort, WritePort
{
    public function __construct(private readonly DedupeRecorder $recorder) {}

    #[AsEventListener(order: 0)]
    public function onEvent(DedupeEvent $event): void
    {
        $this->recorder->record('Repo:listener');
    }
}
