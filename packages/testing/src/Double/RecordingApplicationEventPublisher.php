<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Context\Event\ApplicationEventPublisher;

/**
 * A spy over the context ApplicationEventPublisher port: records every published event IN ORDER, so
 * after-commit tests can assert on WHEN (only after commit) and in what order events arrive. $events
 * matches the deleted data SpyEventPublisher exactly.
 */
final class RecordingApplicationEventPublisher implements ApplicationEventPublisher
{
    /** @var list<object> */
    public array $events = [];

    public function publish(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @param  class-string  $class
     * @return list<object>
     */
    public function ofType(string $class): array
    {
        return array_values(array_filter($this->events, static fn (object $e): bool => $e instanceof $class));
    }
}
