<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\UnstoredParameter;

use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** The constructor takes $id but the event stores $ident: every stored payload would lack `id`. */
#[EventType(name: 'renamed.parameter')]
final readonly class Renamed extends DomainEvent
{
    public string $ident;

    public function __construct(string $id)
    {
        parent::__construct();
        $this->ident = $id;
    }
}
