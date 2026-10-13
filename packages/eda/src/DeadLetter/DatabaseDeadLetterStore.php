<?php

declare(strict_types=1);

namespace Firefly\Eda\DeadLetter;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Firefly\Eda\EventEnvelope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Dead letters that survive the process: one firefly_eda_dead_letters row per exhausted envelope, on any Laravel
 * database connection (firefly.eda.dead-letter.store=database, firefly.eda.dead-letter.connection). The row keeps the
 * whole envelope — event id, type, destination, payload and headers (with RetryingEventHandler's x-original-topic /
 * x-exception) — plus the cause and the instant, so `firefly:eda:dead-letters:replay` can republish it byte for byte.
 * The instant is the application clock in UTC at millisecond precision. The id is a time-ordered UUID (version 7)
 * made in PHP (portable — no driver's identity or sequence) and monotonic within the process: two dead letters
 * written in the same millisecond get increasing ids, and find() orders by (dead_lettered_at, id), so "oldest first"
 * holds even on a tie and `--limit` replays the entry that was written first. The connection is resolved per call, never at construction, so binding the store
 * opens nothing at boot. Independent of the event provider: Kafka, RabbitMQ, memory and the outbox all dead-letter here.
 */
final class DatabaseDeadLetterStore implements ReplayableDeadLetterStore
{
    /**
     * @param  (Closure(): DateTimeImmutable)|null  $clock
     */
    public function __construct(
        private readonly ConnectionResolverInterface $connections,
        private readonly ?string $connection = null,
        private readonly ?Closure $clock = null,
    ) {}

    public function store(EventEnvelope $envelope, Throwable $cause): void
    {
        $now = $this->clock === null ? new DateTimeImmutable : ($this->clock)();

        $this->db()->table(DeadLetterSchema::TABLE)->insert([
            'id' => (string) Str::uuid7(),
            'event_id' => $envelope->eventId,
            'event_type' => $envelope->eventType,
            'destination' => $envelope->destination,
            'payload' => json_encode($envelope->payload === [] ? new stdClass : $envelope->payload, JSON_THROW_ON_ERROR),
            'headers' => json_encode($envelope->headers === [] ? new stdClass : $envelope->headers, JSON_THROW_ON_ERROR),
            'exception_class' => $cause::class,
            'exception_message' => $cause->getMessage(),
            'dead_lettered_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
        ]);
    }

    /**
     * @return list<DeadLetterEntry>
     */
    public function all(): array
    {
        return $this->find(new DeadLetterQuery(limit: null));
    }

    public function find(DeadLetterQuery $query): array
    {
        $select = $this->db()->table(DeadLetterSchema::TABLE)->orderBy('dead_lettered_at')->orderBy('id');

        if ($query->ids !== []) {
            $select->whereIn('id', $query->ids);
        }
        if ($query->destination !== null) {
            $select->where('destination', $query->destination);
        }
        if ($query->eventType !== null) {
            $select->where('event_type', $query->eventType);
        }
        if ($query->limit !== null) {
            $select->limit(max(1, $query->limit));
        }

        $entries = [];
        foreach ($select->get() as $row) {
            $entries[] = self::entry($row);
        }

        return $entries;
    }

    public function remove(string $id): bool
    {
        return $this->db()->table(DeadLetterSchema::TABLE)->where('id', $id)->delete() === 1;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection($this->connection);
    }

    private static function entry(object $row): DeadLetterEntry
    {
        $columns = get_object_vars($row);
        $eventId = self::text($columns['event_id'] ?? null);
        $at = self::text($columns['dead_lettered_at'] ?? null);

        $headers = [];
        foreach (self::map($columns['headers'] ?? null) as $key => $value) {
            if (is_scalar($value)) {
                $headers[$key] = (string) $value;
            }
        }

        return new DeadLetterEntry(
            new EventEnvelope(
                self::text($columns['event_type'] ?? null),
                self::text($columns['destination'] ?? null),
                self::map($columns['payload'] ?? null),
                $headers,
                $eventId === '' ? null : $eventId,
            ),
            self::text($columns['exception_class'] ?? null),
            self::text($columns['exception_message'] ?? null),
            self::text($columns['id'] ?? null),
            $at === '' ? null : new DateTimeImmutable($at, new DateTimeZone('UTC')),
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $json): array
    {
        $decoded = is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;

        $map = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}
