<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Publishing;

use Firefly\EventSourcing\Event\PayloadNormalizer;
use Firefly\EventSourcing\Event\StoredEvent;

/**
 * The eda headers an appended event is published with: its metadata (`x-correlation-id` becomes the outbox row's
 * transaction id) plus where it sits in its stream, so a consumer can deduplicate by `x-event-id` and order by
 * `x-aggregate-version`. The stream headers win over a metadata extra of the same name: metadata rebuilt from an
 * inbound envelope (EventMetadata::fromHeaders) keeps the CAUSING event's `x-event-id` as an extra, and publishing
 * it would make a deduplicating consumer drop this event as a replay of its cause.
 */
final class PublishedHeaders
{
    /** @return array<string, string> */
    public static function for(StoredEvent $event): array
    {
        return [
            'x-event-id' => $event->eventId,
            'x-event-version' => (string) $event->eventVersion,
            'x-aggregate-id' => $event->aggregateId,
            'x-aggregate-type' => $event->aggregateType,
            'x-aggregate-version' => (string) $event->aggregateVersion,
            'x-occurred-at' => $event->occurredAt->format(PayloadNormalizer::DATE_FORMAT),
        ] + $event->metadata->toHeaders();
    }
}
