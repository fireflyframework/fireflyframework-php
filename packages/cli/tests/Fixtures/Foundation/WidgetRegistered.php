<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;

/**
 * A firefly/domain DomainEvent (final readonly, calls parent::__construct). Its eventType() short name
 * ("WidgetRegistered") is the broker event-type published onto the eda bus and matched by WidgetEventListener.
 */
final readonly class WidgetRegistered extends DomainEvent
{
    public function __construct(public string $name, ?string $eventId = null, ?DateTimeImmutable $occurredAt = null)
    {
        parent::__construct($eventId, $occurredAt);
    }
}
