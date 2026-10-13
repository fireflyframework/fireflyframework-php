<?php

declare(strict_types=1);

namespace Lumen\Infrastructure\Loan;

use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Lazy;
use Firefly\EventSourcing\Aggregate\EventSourcedRepository;
use Firefly\EventSourcing\Aggregate\EventSourcingSupport;
use Lumen\Domain\Loan\Loan;

/**
 * Loads and saves loans through firefly/eventsourcing: get() replays the stream (from the newest snapshot when one
 * matches Loan::snapshotVersion()), save() appends the buffered events under the version the loan was loaded at.
 *
 * #[Lazy] because EventSourcingSupport is bound only where firefly/eventsourcing's providers boot; the sample's other
 * harnesses never ask for a loan, so they never build this bean (PayoutController is the precedent).
 *
 * @extends EventSourcedRepository<Loan>
 */
#[Component]
#[Lazy]
final class LoanRepository extends EventSourcedRepository
{
    public function __construct(EventSourcingSupport $support)
    {
        parent::__construct($support, Loan::class);
    }
}
