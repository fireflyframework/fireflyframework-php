<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Events;

use Firefly\Domain\DomainEvent;

/** No #[EventType]: the registry refuses to append it, so nothing is ever stored that cannot be read back. */
final readonly class Untyped extends DomainEvent {}
