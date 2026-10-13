<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\NotADomainEvent;

use Firefly\EventSourcing\Event\Attributes\EventType;

#[EventType(name: 'stray.thing')]
final class Stray {}
