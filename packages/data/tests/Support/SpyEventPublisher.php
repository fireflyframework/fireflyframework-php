<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;

/**
 * A spy over the ApplicationEventPublisher port: records every published event in order. The after-commit tests
 * assert on WHEN (only after commit) and in what ORDER events arrive; the capstone (T17) binds this in place of
 * the real DispatcherEventPublisher so the whole chain publishes to a spy.
 */
final class SpyEventPublisher implements ApplicationEventPublisher
{
    /** @var list<object> */
    public array $events = [];

    public function publish(object $event): void
    {
        $this->events[] = $event;
    }
}
