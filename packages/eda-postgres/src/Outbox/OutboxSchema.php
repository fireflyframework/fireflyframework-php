<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Eda\Database\Outbox\OutboxSchema as DatabaseOutboxSchema;

/**
 * @deprecated since W1 — the outbox moved to firefly/eda-database; use Firefly\Eda\Database\Outbox\OutboxSchema.
 *             Kept for one release so code that names the old class keeps compiling.
 */
final class OutboxSchema extends DatabaseOutboxSchema {}
