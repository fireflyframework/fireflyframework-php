<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use Firefly\Eda\EventEnvelope;
use Throwable;

/**
 * The eda dead-letter sink (pyfly eda/dlq.py EdaDeadLetterStore parity). A handler that exhausts its retries has
 * its envelope stored here (enriched with x-original-topic / x-exception headers), so no event is silently lost.
 * InMemoryDeadLetterStore is the default; a durable adapter can land at SP-4.
 */
interface DeadLetterStore
{
    public function store(EventEnvelope $envelope, Throwable $cause): void;

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array;
}
