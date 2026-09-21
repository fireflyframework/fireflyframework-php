<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

/**
 * When a #[TransactionalEventListener] runs relative to the transaction its event was published in — Spring's
 * TransactionPhase. BEFORE_COMMIT runs inside the transaction just before the commit (a throw aborts it);
 * AFTER_COMMIT after a successful commit; AFTER_ROLLBACK after a rollback; AFTER_COMPLETION after either.
 * String-backed so the manifest serialises the name verbatim.
 */
enum TransactionPhase: string
{
    case BEFORE_COMMIT = 'BEFORE_COMMIT';
    case AFTER_COMMIT = 'AFTER_COMMIT';
    case AFTER_ROLLBACK = 'AFTER_ROLLBACK';
    case AFTER_COMPLETION = 'AFTER_COMPLETION';
}
