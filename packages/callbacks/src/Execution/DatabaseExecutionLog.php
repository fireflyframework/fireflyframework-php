<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Execution;

use Firefly\Callbacks\Schema\CallbackSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * log.store=database: executions as rows of firefly_callback_executions on any Laravel connection. record() is
 * INSERT, then UPDATE on a primary-key violation — the DatabaseSubscriptionRegistry reasoning: never "update, and
 * insert when no row changed", because MySQL reports zero affected rows for an update that changes nothing, and the
 * insert runs in a nested transaction so a violation inside a caller's Postgres transaction leaves it usable. The
 * UPDATE never touches created_at, so the first record's time survives every retry and replay. recent() orders by
 * updated_at then id, both descending, so equal timestamps still list deterministically.
 */
final class DatabaseExecutionLog implements ExecutionLog
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function record(CallbackExecution $execution): void
    {
        $row = [
            'subscription_id' => $execution->subscriptionId,
            'event_id' => $execution->eventId,
            'event_type' => $execution->eventType,
            'url' => $execution->url,
            'body' => $execution->body,
            'status' => $execution->status->value,
            'attempts' => $execution->attempts,
            'response_status' => $execution->responseStatus,
            'last_error' => $execution->lastError,
            'updated_at' => $execution->updatedAt,
        ];

        try {
            $this->connection->transaction(fn (): bool => $this->connection->table(CallbackSchema::EXECUTIONS)->insert(['id' => $execution->id, 'created_at' => $execution->createdAt] + $row));
        } catch (UniqueConstraintViolationException) {
            $this->connection->table(CallbackSchema::EXECUTIONS)->where('id', $execution->id)->update($row);
        }
    }

    public function find(string $id): ?CallbackExecution
    {
        $row = $this->connection->table(CallbackSchema::EXECUTIONS)->where('id', $id)->first();

        return is_object($row) ? self::hydrate($row) : null;
    }

    public function recent(int $limit = 20, ?CallbackStatus $status = null, ?string $subscriptionId = null): array
    {
        if ($limit < 1) {
            return [];
        }

        $query = $this->connection->table(CallbackSchema::EXECUTIONS);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        if ($subscriptionId !== null) {
            $query->where('subscription_id', $subscriptionId);
        }

        $executions = [];
        foreach ($query->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get() as $row) {
            $executions[] = self::hydrate($row);
        }

        return $executions;
    }

    private static function hydrate(object $row): CallbackExecution
    {
        $values = get_object_vars($row);
        $responseStatus = $values['response_status'] ?? null;
        $lastError = $values['last_error'] ?? null;

        return new CallbackExecution(
            self::text($values['id'] ?? ''),
            self::text($values['subscription_id'] ?? ''),
            self::text($values['event_id'] ?? ''),
            self::text($values['event_type'] ?? ''),
            self::text($values['url'] ?? ''),
            self::text($values['body'] ?? ''),
            CallbackStatus::tryFrom(self::text($values['status'] ?? '')) ?? CallbackStatus::Pending,
            self::integer($values['attempts'] ?? 0),
            is_numeric($responseStatus) ? (int) $responseStatus : null,
            is_string($lastError) ? $lastError : null,
            self::integer($values['created_at'] ?? 0),
            self::integer($values['updated_at'] ?? 0),
        );
    }

    /** A column read back as text: drivers return strings (or ints for numeric-looking ids), never anything else here. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** An integer column: PDO returns ints on most drivers and numeric strings on some (MySQL without native types). */
    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
