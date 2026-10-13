<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\DuplicateType;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

#[EventType(name: 'twice.named')]
final readonly class First extends DomainEvent {}
