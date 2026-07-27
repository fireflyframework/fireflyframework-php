<?php

declare(strict_types=1);

namespace Firefly\Testing\Double;

use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Domain\DomainEvent;
use Throwable;

/** Records every committed DomainEvent the bridge re-emits; optionally throws to exercise failure paths. */
final class RecordingCommandEventPublisher implements CommandEventPublisher
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function __construct(private readonly ?Throwable $throw = null) {}

    public function publish(DomainEvent $event, ?string $destination = null): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->published[] = $event;
    }
}
