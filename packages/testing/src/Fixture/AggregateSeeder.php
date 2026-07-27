<?php

declare(strict_types=1);

namespace Firefly\Testing\Fixture;

use Firefly\Context\Event\ApplicationEventPublisher;

/** Firefly-specific fixture convenience: replay an aggregate's domain events through the publisher port. */
final class AggregateSeeder
{
    public function publishEvents(ApplicationEventPublisher $publisher, object ...$events): void
    {
        foreach ($events as $event) {
            $publisher->publish($event);
        }
    }
}
