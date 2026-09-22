<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction\Attributes;

use Attribute;
use Firefly\Data\Transaction\TransactionPhase;

/**
 * An application-event listener that runs in a phase of the transaction the event was published in —
 * Spring's @TransactionalEventListener, the transaction-aware alternative to #[AsEventListener]. The event is
 * published immediately (a plain #[AsEventListener] on the same class still sees it inside the transaction);
 * THIS listener is queued on the current transaction and invoked in `$phase`. With no transaction active it
 * is skipped, unless `fallbackExecution` is true, in which case it runs at once.
 *
 * `$event` null infers the event class from the method's first parameter type, exactly like #[AsEventListener].
 * `$order` follows the #[Order] convention (lower first) among transactional listeners of the same phase.
 * INERT METADATA: TransactionalScanner compiles it into the manifest's `listeners` map, the
 * TransactionalEventListenerWiringPass registers it, TransactionSynchronizationRegistry queues it.
 *
 * Relationship with DomainEventDispatcher::publishAfterCommit(): that defers the EVENT (nobody hears it before
 * commit); this defers the LISTENER (the event is heard now, this method later).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class TransactionalEventListener
{
    public function __construct(
        public ?string $event = null,
        public TransactionPhase $phase = TransactionPhase::AFTER_COMMIT,
        public bool $fallbackExecution = false,
        public int $order = 0,
    ) {}
}
