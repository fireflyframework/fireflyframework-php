<?php

declare(strict_types=1);

namespace Lumen\Domain\Loan\Event;

use DateTimeImmutable;
use Firefly\Domain\DomainEvent;
use Firefly\EventSourcing\Event\Attributes\EventType;

/**
 * One repayment of a loan, and what is still outstanding after it. Version 2: the first loans Lumen recorded called
 * the amount `amount`, and the field was renamed `amountMinor` to match every other money field in the sample. Stored
 * events are never rewritten, so LoanRepaidV1Upcaster moves a version-1 payload forward each time one is read.
 */
#[EventType(name: 'loan.repaid', version: 2)]
final readonly class LoanRepaid extends DomainEvent
{
    public function __construct(
        public string $loanId,
        public int $amountMinor,
        public int $outstandingMinor,
        ?string $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($eventId, $occurredAt);
    }
}
