<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Eda\Database\Outbox\RelayDownstream as DatabaseRelayDownstream;

/**
 * @deprecated since W1 — use Firefly\Eda\Database\Outbox\RelayDownstream. firefly.eda.postgres.relay.downstream_provider
 *             is still honoured as the legacy alias of firefly.eda.database.relay.downstream-provider.
 */
final class RelayDownstream extends DatabaseRelayDownstream {}
