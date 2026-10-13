<?php

declare(strict_types=1);

namespace Lumen\Domain\Loan\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/** Recorded with the repayment that brings a loan's outstanding balance to zero; a settled loan takes no more. */
#[EventType(name: 'loan.settled')]
final readonly class LoanSettled extends DomainEvent
{
    public function __construct(
        public string $loanId,
        public int $repayments,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
