<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Outbox;

use Firefly\Eda\Database\Outbox\Claim\OptimisticLeaseClaim;
use Firefly\Eda\Database\Outbox\Claim\OutboxClaimStrategies;
use Firefly\Eda\Database\Outbox\OutboxRelay as DatabaseOutboxRelay;
use Firefly\Eda\EventPublisher;
use Firefly\Eda\Tracing\EdaTracing;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * @deprecated since W1 — use Firefly\Eda\Database\Outbox\OutboxRelay. This keeps the old constructor for one release.
 *             `$useSkipLocked` keeps its intent, mapped onto the lease claims: `true` ("lock the rows I take") becomes
 *             the capability-probed `auto` strategy — SKIP LOCKED wherever the server supports it, never an SQL error
 *             where it does not — and `false` ("take no row locks") becomes the portable optimistic lease, which
 *             also takes none. The old `false` was an unlocked read that two relays could double-forward; the
 *             optimistic lease is not.
 */
final class OutboxRelay extends DatabaseOutboxRelay
{
    public function __construct(
        ConnectionInterface $connection,
        EventPublisher $downstream,
        int $batchSize = 50,
        int $maxAttempts = 3,
        bool $useSkipLocked = false,
        ?EdaTracing $tracing = null,
    ) {
        if (! $connection instanceof Connection) {
            throw new InvalidArgumentException('OutboxRelay needs a concrete Illuminate\Database\Connection: the claim reads its driver and server version.');
        }

        parent::__construct(
            $connection,
            $downstream,
            OutboxClaimStrategies::configured($useSkipLocked ? OutboxClaimStrategies::AUTO : OptimisticLeaseClaim::NAME),
            $batchSize,
            $maxAttempts,
            $tracing,
        );
    }
}
