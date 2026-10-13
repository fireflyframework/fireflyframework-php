<?php

declare(strict_types=1);

namespace App\Greetings;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;

/** @extends EventSourcedRepository<GreetingJournal> */
#[Component]
final class GreetingJournals extends EventSourcedRepository
{
    public function __construct(EventSourcingSupport $support)
    {
        parent::__construct($support, GreetingJournal::class);
    }
}
