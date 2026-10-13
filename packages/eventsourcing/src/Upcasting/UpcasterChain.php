<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Upcasting;

use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Exception\UpcastingException;

/**
 * Every registered upcaster, indexed by (type, version), applied by the stores to every event they hand out. Each
 * step must change the type or the version; after MAX_STEPS steps the chain is a loop and is refused. Equal
 * priorities keep registration order (usort is stable).
 */
final class UpcasterChain
{
    public const int MAX_STEPS = 64;

    /** @var array<string, EventUpcaster> "type#version" => the winning upcaster */
    private array $byKey = [];

    /**
     * @param  iterable<EventUpcaster>  $upcasters
     */
    public function __construct(iterable $upcasters = [])
    {
        $sorted = [];
        foreach ($upcasters as $upcaster) {
            $sorted[] = $upcaster;
        }
        usort($sorted, static fn (EventUpcaster $a, EventUpcaster $b): int => $b->priority() <=> $a->priority());

        foreach ($sorted as $upcaster) {
            $this->byKey[$upcaster->eventType().'#'.$upcaster->fromVersion()] ??= $upcaster;
        }
    }

    public function isEmpty(): bool
    {
        return $this->byKey === [];
    }

    public function apply(StoredEvent $event): StoredEvent
    {
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            $upcaster = $this->byKey[$event->eventType.'#'.$event->eventVersion] ?? null;
            if ($upcaster === null) {
                return $event;
            }

            $next = $upcaster->upcast($event);
            if ($next->eventType === $event->eventType && $next->eventVersion === $event->eventVersion) {
                throw UpcastingException::noProgress($event->eventType, $event->eventVersion);
            }
            $event = $next;
        }

        throw UpcastingException::loop($event->eventType);
    }
}
