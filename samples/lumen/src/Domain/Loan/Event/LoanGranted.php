<?php

declare(strict_types=1);

namespace Lumen\Domain\Loan\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/**
 * The first event of every loan: who it was granted to, how much, in which currency. #[EventType] gives it the stable
 * name the event store keeps (`loan.granted`, never the class name, so the class can move) and firefly:cache compiles
 * it into the event-sourcing manifest. eventId and occurredAt are forwarded to the parent so a reloaded event keeps
 * the identity and the time it was recorded with.
 */
#[EventType(name: 'loan.granted')]
final readonly class LoanGranted extends DomainEvent
{
    public function __construct(
        public string $loanId,
        public string $walletId,
        public int $principalMinor,
        public string $currency,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
