<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures;

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Projection\InlineProjection;

/** An inline projection that records the positions it was fed and how often it was reset. */
final class RecordingInlineProjection implements InlineProjection
{
    /** @var list<int> */
    public array $positions = [];

    public int $resets = 0;

    public function name(): string
    {
        return 'recorded.inline';
    }

    public function handles(): array
    {
        return [];
    }

    public function handle(StoredEvent $event): void
    {
        $this->positions[] = (int) $event->globalPosition;
    }

    public function reset(): void
    {
        $this->resets++;
        $this->positions = [];
    }
}
