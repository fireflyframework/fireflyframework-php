<?php

declare(strict_types=1);

namespace Lumen\Infrastructure\Loan;

use Firefly\Container\Attributes\Component;
use Firefly\EventSourcing\Event\StoredEvent;
use Firefly\EventSourcing\Upcasting\EventUpcaster;

/**
 * `loan.repaid` version 1 called the repaid amount `amount`; version 2 calls it `amountMinor`. Events are immutable,
 * so the stored row keeps its version-1 payload forever and this upcaster renames the field each time the event is
 * READ — by LoanRepository and by LoanBookProjection alike, because the store upcasts before either sees it. A
 * #[Component], so the UpcasterChain collects it.
 */
#[Component]
final class LoanRepaidV1Upcaster implements EventUpcaster
{
    public function eventType(): string
    {
        return 'loan.repaid';
    }

    public function fromVersion(): int
    {
        return 1;
    }

    public function priority(): int
    {
        return 0;
    }

    public function upcast(StoredEvent $event): StoredEvent
    {
        $payload = $event->payload;
        $payload['amountMinor'] = $payload['amount'] ?? 0;
        unset($payload['amount']);

        return $event->upcasted('loan.repaid', 2, $payload);
    }
}
