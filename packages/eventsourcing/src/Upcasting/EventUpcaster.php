<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Upcasting;

use Firefly\EventSourcing\Event\StoredEvent;

/**
 * Moves a stored event of eventType() at fromVersion() one step forward on READ: a higher version, a new type, or
 * both. A #[Component] implementing this is collected into the UpcasterChain. When two upcasters claim the same
 * (type, version), the higher priority() wins. Stored rows are never rewritten.
 */
interface EventUpcaster
{
    public function eventType(): string;

    public function fromVersion(): int;

    public function priority(): int;

    public function upcast(StoredEvent $event): StoredEvent;
}
