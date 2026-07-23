<?php

declare(strict_types=1);

namespace Firefly\Messaging\DeadLetter;

use Firefly\Messaging\Message;
use Throwable;

/**
 * The messaging dead-letter sink (pyfly messaging/error_handling.py parity, bytes flavour). A consumer that
 * exhausts its retries has its message stored here re-keyed to its deadLetterTopic. Distinct from eda's
 * DeadLetterStore (envelope vs raw-bytes) — no shared class, no cross-package edge.
 */
interface DeadLetterStore
{
    public function store(Message $message, Throwable $cause): void;

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array;
}
