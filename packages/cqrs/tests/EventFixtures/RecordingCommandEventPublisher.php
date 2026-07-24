<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\EventFixtures;

use Firefly\Cqrs\Event\CommandEventPublisher;
use Firefly\Domain\DomainEvent;

final class RecordingCommandEventPublisher implements CommandEventPublisher
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function __construct(private readonly ?\Throwable $throw = null) {}

    public function publish(DomainEvent $event, ?string $destination = null): void
    {
        if ($this->throw !== null) {
            throw $this->throw;
        }

        $this->published[] = $event;
    }
}
