<?php

declare(strict_types=1);

namespace Firefly\Webhooks\Idempotency;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * idempotency.store=database: claims are rows of firefly_webhook_receipts on any Laravel connection.
 *
 * claim() is an INSERT; the primary key is the atomicity, and Laravel reports a violation as
 * UniqueConstraintViolationException on pgsql, mysql/mariadb, sqlite and sqlsrv alike — no driver-specific SQL. A
 * violation means "duplicate" unless the existing row has expired: that row is deleted CONDITIONALLY (expires_at at
 * or before now, so a concurrent fresh claim is never deleted) and the insert is tried once more. Each insert runs in
 * its own (nested) transaction, i.e. a savepoint inside an outer one, so a failed insert does not abort a Postgres
 * transaction the caller had open.
 */
final class DatabaseWebhookIdempotencyStore implements WebhookIdempotencyStore
{
    /** @var Closure(): int */
    private readonly Closure $clock;

    /**
     * @param  (Closure(): int)|null  $clock  unix seconds
     */
    public function __construct(private readonly ConnectionInterface $connection, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function claim(string $provider, string $key, string $eventId, float $ttlSeconds): bool
    {
        $now = ($this->clock)();
        $row = [
            'provider' => $provider,
            'idempotency_key' => $key,
            'event_id' => $eventId,
            'claimed_at' => $now,
            'expires_at' => $now + max(1, (int) ceil($ttlSeconds)),
        ];

        if ($this->insert($row)) {
            return true;
        }

        $expired = $this->connection->table(WebhookSchema::TABLE)
            ->where('provider', $provider)
            ->where('idempotency_key', $key)
            ->where('expires_at', '<=', $now)
            ->delete();

        return $expired > 0 && $this->insert($row);
    }

    public function release(string $provider, string $key): void
    {
        $this->connection->table(WebhookSchema::TABLE)->where('provider', $provider)->where('idempotency_key', $key)->delete();
    }

    /** Deletes every expired receipt; what firefly:webhooks:prune runs. */
    public function prune(): int
    {
        return $this->connection->table(WebhookSchema::TABLE)->where('expires_at', '<=', ($this->clock)())->delete();
    }

    /**
     * One INSERT attempt: true when the row went in, false when the primary key already holds a receipt. Impure by
     * definition (it writes, and its answer depends on the table), so a second attempt after the expired-row delete
     * is a fresh question, not a repeat of the first.
     *
     * @param  array<string, string|int>  $row
     *
     * @phpstan-impure
     */
    private function insert(array $row): bool
    {
        try {
            $this->connection->transaction(fn (): bool => $this->connection->table(WebhookSchema::TABLE)->insert($row));

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
