<?php

declare(strict_types=1);

namespace Firefly\Data\Domain;

/**
 * An OPTIONAL seam invoked SYNCHRONOUSLY for each drained domain event while the aggregate's transaction is still
 * open (DomainEventDispatcher::dispatchAfterCommit runs inside the tx — TransactionTemplate calls it before commit),
 * so an implementation that writes a row commits ATOMICALLY with the aggregate and rolls back with it. The default is
 * null: when unbound, DomainEventDispatcher behaves exactly as in M8 (publish deferred to DB::afterCommit only). The
 * genuine same-transaction outbox (firefly/eda-postgres) binds an implementation; no other code path is affected.
 * $connection is the aggregate's tx connection name (threaded from DomainEventDispatcher::afterCommit), so the
 * implementation writes on THAT connection — a #[Transactional(connection:'x')] aggregate's outbox row is same-tx on x.
 */
interface PreCommitEventHook
{
    public function handle(object $event, ?string $connection = null): void;
}
