<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Outbox;

/**
 * Marks an EventPublisher that writes INTO firefly_eda_outbox — DatabaseEventPublisher and its Postgres subclass.
 * The relay refuses any OutboxWriter as its downstream: forwarding a claimed row through one would re-INSERT it as
 * PENDING into the table being drained, and claim it again forever.
 */
interface OutboxWriter {}
